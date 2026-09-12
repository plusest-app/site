<?php

namespace Plusest\Site\Feed;

use Plusest\Site\Config;
use Plusest\Site\Exception\FeedException;
use Plusest\Site\Exception\FeedRefusedException;

/**
 * Отримує фід обʼєктів з CRM.
 *
 * Робить один запит за посиланням публікації, повторює його при збоях мережі
 * і повертає розібраний фід у вигляді обʼєкта Feed.
 *
 * Використання:
 *
 *     $client = Client::fromConfig($config);
 *     $feed = $client->fetch();
 *
 *     echo $feed->total();          // скільки публікацій у фіді
 *     foreach ($feed->items() as $item) { ... }
 */
class Client
{
    /**
     * Значення заголовка User-Agent у запитах до CRM і до хмарного сховища.
     *
     * У config.php цього значення немає навмисно: за ним CRM бачить, що запит
     * прийшов саме від заготовки сайту, і при потребі враховує її версію.
     * Користувачу змінювати його немає для чого.
     */
    const USER_AGENT = 'PlusestSite/1.0';

    /**
     * Посилання публікації з CRM.
     *
     * @var string
     */
    private $url;

    /**
     * Скільки секунд чекати відповідь.
     *
     * @var int
     */
    private $timeout;

    /**
     * Скільки разів повторити запит при збої.
     *
     * @var int
     */
    private $retries;

    /**
     * Пауза між спробами, секунди.
     *
     * @var int
     */
    private $retryDelay;

    /**
     * Чи перевіряти SSL-сертифікат.
     *
     * @var bool
     */
    private $verifySsl;

    /**
     * Куди писати повідомлення про хід роботи. Приймає один рядок тексту.
     *
     * @var callable|null
     */
    private $logger;

    /**
     * @param string $url     Посилання публікації
     * @param array  $options Ключі timeout, retries, retryDelay, verifySsl
     */
    public function __construct($url, array $options = [])
    {
        $this->url = (string) $url;
        $this->timeout = isset($options['timeout']) ? (int) $options['timeout'] : 180;
        $this->retries = isset($options['retries']) ? max(1, (int) $options['retries']) : 3;
        $this->retryDelay = isset($options['retryDelay']) ? (int) $options['retryDelay'] : 5;
        $this->verifySsl = isset($options['verifySsl']) ? (bool) $options['verifySsl'] : true;
    }

    /**
     * Створює клієнта з налаштувань.
     *
     * @param Config $config Налаштування
     *
     * @return self
     */
    public static function fromConfig(Config $config)
    {
        return new self($config->need('feed.url'), (array) $config->get('feed', []));
    }

    /**
     * Призначає обробник, у який пишуться повідомлення про хід запиту.
     *
     * @param callable|null $logger Функція, що приймає рядок тексту
     *
     * @return $this
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Забирає фід і повертає розібраний обʼєкт.
     *
     * @return Feed
     *
     * @throws FeedException Якщо не вдалося отримати або розібрати відповідь
     */
    public function fetch()
    {
        $body = $this->request($this->url);

        return Feed::fromJson($body);
    }

    /**
     * Завантажує довільний URL тим самим клієнтом.
     *
     * Використовується для завантаження JSON-моделей даних: налаштування
     * таймаутів і повторів там потрібні ті самі, що й для фіда.
     *
     * @param string $url Адреса
     *
     * @return string Тіло відповіді
     *
     * @throws FeedException Якщо запит не вдався
     */
    public function download($url)
    {
        return $this->request($url);
    }

    /**
     * Виконує GET-запит із повторами.
     *
     * @param string $url Адреса
     *
     * @return string Тіло відповіді
     *
     * @throws FeedException Якщо всі спроби провалились
     */
    private function request($url)
    {
        if (!extension_loaded('curl')) {
            throw new FeedException('Для роботи з CRM потрібне розширення PHP curl.');
        }

        if (!preg_match('~^https?://~i', $url)) {
            throw new FeedException(
                'Некоректне посилання на фід: "' . $url . '".' . "\n"
                . 'Воно має починатися з https:// — скопіюйте посилання публікації з кабінету CRM.'
            );
        }

        $lastError = '';
        $refused = false;

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            if ($attempt > 1) {
                $this->log('Повторна спроба ' . $attempt . ' з ' . $this->retries
                    . ' через ' . $this->retryDelay . ' с. Причина: ' . $lastError);

                sleep($this->retryDelay);
            }

            $result = $this->requestOnce($url);

            if ($result['ok']) {
                $this->log('Отримано ' . $this->formatBytes(strlen($result['body']))
                    . ' за ' . round($result['time'], 2) . ' с');

                return $result['body'];
            }

            $lastError = $result['error'];
            $refused = $result['refused'];

            // Помилки, які не виправляться повтором: невірне посилання,
            // немає доступу, сторінки не існує. Повторювати їх немає сенсу.
            if (!$result['retryable']) {
                break;
            }
        }

        $message = 'Не вдалося отримати дані з CRM. ' . $lastError;

        // Відмову сервера виділяємо окремим типом: після неї синхронізація
        // відкладе наступне звернення на кілька годин.
        throw $refused ? new FeedRefusedException($message) : new FeedException($message);
    }

    /**
     * Одна спроба запиту.
     *
     * @param string $url Адреса
     *
     * @return array{ok: bool, body: string, error: string, retryable: bool, refused: bool, time: float}
     *                refused = true означає, що сервер відповів і відмовив —
     *                на відміну від збою звʼязку, який може минути сам
     */
    private function requestOnce($url)
    {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(30, $this->timeout),
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,

            // Порожній рядок означає «прийму будь-яке стиснення, яке вміє curl».
            // Фід великого агентства стискається у кілька разів, тому це
            // помітно економить час завантаження.
            CURLOPT_ENCODING       => '',

            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $time = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);

        curl_close($handle);

        // --- Помилка на рівні мережі ----------------------------------------
        if ($errorNumber !== 0 || $body === false) {
            return [
                'ok'        => false,
                'body'      => '',
                'error'     => 'Помилка звʼязку: ' . $errorMessage . ' (код curl ' . $errorNumber . ')',
                'retryable' => true,
                'refused'   => false,
                'time'      => $time,
            ];
        }

        // --- Помилка на рівні HTTP ------------------------------------------
        if ($httpCode !== 200) {
            // 5xx — проблема на боці CRM, вона може минути.
            $retryable = $httpCode >= 500 || $httpCode === 0;

            // 4xx означає, що сервер нас зрозумів і відмовив: невірне
            // посилання, немає доступу, забагато запитів. Повторювати марно,
            // і наступне звернення слід відкласти надовго.
            $refused = $httpCode >= 400 && $httpCode < 500;

            return [
                'ok'        => false,
                'body'      => '',
                'error'     => 'CRM відповіла кодом HTTP ' . $httpCode . '.'
                    . ($httpCode === 404 ? ' Перевірте посилання публікації у налаштуваннях.' : '')
                    . ($httpCode === 403 ? ' Можливо, публікацію вимкнено в кабінеті CRM.' : '')
                    . ($httpCode === 429 ? ' Забагато запитів — зменшіть частоту синхронізації.' : ''),
                'retryable' => $retryable,
                'refused'   => $refused,
                'time'      => $time,
            ];
        }

        // --- Замість JSON прийшло щось інше ---------------------------------
        if ($body === '') {
            return [
                'ok'        => false,
                'body'      => '',
                'error'     => 'CRM повернула порожню відповідь.',
                'retryable' => true,
                'refused'   => false,
                'time'      => $time,
            ];
        }

        // Типова ситуація на кривих проксі та в капітивних мережах: замість
        // JSON віддається HTML-заглушка. Ловимо це до розбору JSON, щоб
        // повідомлення було зрозумілим.
        if ($contentType !== '' && !preg_match('~json|text/plain~i', $contentType)) {
            return [
                'ok'        => false,
                'body'      => '',
                'error'     => 'CRM повернула дані типу "' . $contentType . '" замість JSON.',
                'retryable' => false,

                // Це не відмова сервера, а щось між нами і CRM — проксі або
                // фільтр провайдера. Відкладати запити на години не варто.
                'refused'   => false,
                'time'      => $time,
            ];
        }

        return [
            'ok'        => true,
            'body'      => $body,
            'error'     => '',
            'retryable' => false,
            'refused'   => false,
            'time'      => $time,
        ];
    }

    /**
     * Пише повідомлення в обробник, якщо він призначений.
     *
     * @param string $message Текст
     *
     * @return void
     */
    private function log($message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }

    /**
     * Розмір у зручному для читання вигляді.
     *
     * @param int $bytes Кількість байтів
     *
     * @return string
     */
    private function formatBytes($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' КБ';
        }

        return round($bytes / 1024 / 1024, 2) . ' МБ';
    }
}

<?php

namespace Plusest\Site\Photo;

use Plusest\Site\Config;
use Plusest\Site\Feed\Client;

/**
 * Завантажує один файл фотографії з хмарного сховища CRM.
 *
 * Навіщо окремий клас, а не Feed\Client
 * -------------------------------------
 * Клієнт фіда повертає тіло відповіді рядком і вимагає JSON. Тут потрібно
 * інше: писати відповідь одразу у файл, не тримаючи її в памʼяті, і
 * перевіряти, що прийшло саме зображення.
 *
 * Повторів усередині немає навмисно. Фотографій за прогін завантажуються
 * сотні; якщо сховище недоступне, повтори на кожному файлі перетворять
 * прогін на багатогодинне очікування. Замість цього невдала фотографія
 * просто залишається на наступний прогін cron — і синхронізація фотографій
 * припиняє спроби, коли помилки йдуть одна за одною.
 */
class Downloader
{
    /**
     * Максимальний розмір файлу, який погоджуємось прийняти.
     *
     * Захист від ситуації, коли за посиланням лежить не фотографія, а щось
     * велике: 32 МБ з великим запасом перекривають будь-яке фото з CRM.
     */
    const MAX_BYTES = 33554432;

    /**
     * Скільки секунд чекати завантаження одного файлу.
     *
     * @var int
     */
    private $timeout;

    /**
     * Чи перевіряти SSL-сертифікат.
     *
     * @var bool
     */
    private $verifySsl;

    /**
     * @param int  $timeout   Таймаут у секундах
     * @param bool $verifySsl Перевіряти сертифікат
     */
    public function __construct($timeout = 60, $verifySsl = true)
    {
        $this->timeout = max(5, (int) $timeout);
        $this->verifySsl = (bool) $verifySsl;
    }

    /**
     * Створює завантажувач із налаштувань.
     *
     * Таймаут беремо з sync.photoTimeout, а перевірку сертифіката — з секції
     * фіда: сховище живе на тому самому боці, і окремо налаштовувати те
     * саме двічі користувачу не потрібно.
     *
     * @param Config $config Налаштування
     *
     * @return self
     */
    public static function fromConfig(Config $config)
    {
        return new self(
            $config->get('sync.photoTimeout', 60),
            $config->get('feed.verifySsl', true)
        );
    }

    /**
     * Завантажує файл за посиланням.
     *
     * Пише напряму в $targetFile. Якщо завантаження не вдалося, файл
     * видаляється — щоб напівзаписаний огарок не сплутали з готовим.
     *
     * @param string $url        Посилання на фотографію
     * @param string $targetFile Куди писати
     *
     * @return array{ok: bool, code: int, bytes: int, error: string, retryable: bool}
     *                            retryable = true означає «спробувати ще раз
     *                            наступним прогоном»; false — фотографії за
     *                            цією адресою більше немає
     */
    public function toFile($url, $targetFile)
    {
        if (!extension_loaded('curl')) {
            return $this->failure(0, 'немає розширення PHP curl', false);
        }

        $handle = @fopen($targetFile, 'wb');

        if ($handle === false) {
            return $this->failure(0, 'немає прав на запис у ' . dirname($targetFile), false);
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(30, $this->timeout),
            CURLOPT_USERAGENT      => Client::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        curl_exec($curl);

        $errorNumber = curl_errno($curl);
        $errorMessage = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

        curl_close($curl);
        fclose($handle);

        // --- Збій мережі -----------------------------------------------------
        if ($errorNumber !== 0) {
            @unlink($targetFile);

            return $this->failure(0, 'помилка звʼязку: ' . $errorMessage, true);
        }

        // --- Відповідь не 200 ------------------------------------------------
        if ($httpCode !== 200) {
            @unlink($targetFile);

            // 404 і 403 означають, що фотографії за цією адресою немає й не
            // буде: у CRM її видалили або замінили. Повторювати марно.
            $retryable = $httpCode >= 500 || $httpCode === 429 || $httpCode === 0;

            return $this->failure($httpCode, 'сховище відповіло кодом HTTP ' . $httpCode, $retryable);
        }

        $bytes = is_file($targetFile) ? (int) filesize($targetFile) : 0;

        if ($bytes === 0) {
            @unlink($targetFile);

            return $this->failure($httpCode, 'сховище віддало порожній файл', true);
        }

        if ($bytes > self::MAX_BYTES) {
            @unlink($targetFile);

            return $this->failure($httpCode, 'файл завеликий (' . $bytes . ' Б)', false);
        }

        // Замість зображення могла прийти HTML-сторінка помилки. Розбір
        // такого файлу все одно впаде далі, але зрозуміліше сказати про це
        // тут — і не витрачати час на спробу масштабування.
        if ($contentType !== '' && !preg_match('~^image/~i', $contentType)) {
            @unlink($targetFile);

            return $this->failure($httpCode, 'замість зображення прийшло "' . $contentType . '"', false);
        }

        return [
            'ok'        => true,
            'code'      => $httpCode,
            'bytes'     => $bytes,
            'error'     => '',
            'retryable' => false,
        ];
    }

    /**
     * Формує відповідь про невдале завантаження.
     *
     * @param int    $code      Код HTTP, якщо він відомий
     * @param string $error     Опис причини
     * @param bool   $retryable Чи є сенс повторити наступним прогоном
     *
     * @return array{ok: bool, code: int, bytes: int, error: string, retryable: bool}
     */
    private function failure($code, $error, $retryable)
    {
        return [
            'ok'        => false,
            'code'      => (int) $code,
            'bytes'     => 0,
            'error'     => $error,
            'retryable' => (bool) $retryable,
        ];
    }
}

<?php

namespace Plusest\Site\Web;

use Exception;
use Plusest\Site\Config;
use Plusest\Site\Db;
use Plusest\Site\Debug;
use Plusest\Site\Model\ModelStore;
use Plusest\Site\Repository\MetroRepository;
use Plusest\Site\Repository\SearchRepository;
use Plusest\Site\Search\Filters;
use Plusest\Site\Search\Query;
use Plusest\Site\Sync\StateStore;

/**
 * Front controller розділу нерухомості.
 *
 * Через цей клас проходить кожен запит відвідувача: public/index.php лише
 * завантажує конфіг і викликає run().
 *
 * Що робить один запит
 * --------------------
 *  1. Визначає мову та валюту. Вони зберігаються в куках, а перемикачі
 *     приходять параметром (?lang=ru) — після чого відбувається редирект на
 *     ту саму адресу без параметра, щоб у пошуковий індекс не потрапляли
 *     адреси з налаштуваннями відвідувача.
 *  2. Приймає форму фільтра. Форма надсилається звичайним GET, а тут її
 *     параметри перетворюються у ЧПУ і віддається редирект. Тому фільтр
 *     працює без JavaScript, а адреса завжди виглядає однаково.
 *  3. Визначає сторінку: список обʼєктів або одна публікація.
 *  4. Перевіряє, чи адреса канонічна. Якщо той самий фільтр можна записати
 *     коротше (наприклад '/page-1/' або переставлені місцями частини) —
 *     віддає 301 на канонічну адресу.
 *  5. Рендерить шаблон.
 *
 * Помилки
 * -------
 * Будь-яке виключення перетворюється на сторінку «щось пішло не так» із
 * кодом 500. Текст помилки показується лише коли в конфізі debug.enabled =
 * true: на робочому сайті відвідувач не мусить бачити ні шляхів на сервері,
 * ні тексту SQL.
 *
 * Чому сторінка повільна
 * ----------------------
 * Якщо в конфізі debug.summary = true, у кінець кожної відповіді
 * дописується HTML-комментар із хронометражем: скільки тривав кожен етап і
 * кожен запит до бази. Етапи позначає mark() по ходу обробки запиту, а
 * запити записує Db. Див. клас Debug.
 */
class Application
{
    /**
     * Кука, у якій зберігається вибрана мова.
     */
    const COOKIE_LANG = 'plusestLang';

    /**
     * Кука, у якій зберігається вибрана валюта.
     */
    const COOKIE_CURRENCY = 'plusestCurrency';

    /**
     * Скільки днів зберігати вибір мови та валюти.
     */
    const COOKIE_DAYS = 365;

    /**
     * Параметр, яким форма фільтра позначає себе.
     *
     * Без нього не відрізнити «форму надіслали порожньою» від «звичайного
     * запиту сторінки».
     */
    const FILTER_FLAG = 'filter';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Db
     */
    private $db;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Url
     */
    private $url;

    /**
     * @var Translator
     */
    private $t;

    /**
     * @var ModelStore
     */
    private $models;

    /**
     * @var View
     */
    private $view;

    /**
     * @var Present
     */
    private $present;

    /**
     * @var SearchRepository
     */
    private $search;

    /**
     * @var MetroRepository
     */
    private $metro;

    /**
     * Збирач сводки налагодження.
     *
     * @var Debug
     */
    private $debug;

    /**
     * Поточна мова.
     *
     * @var string
     */
    private $lang;

    /**
     * Поточна валюта.
     *
     * @var string
     */
    private $currency;

    /**
     * @param Config       $config  Налаштування
     * @param Db|null      $db      Підключення до бази; створюється з конфігу, якщо не передане
     * @param Request|null $request Запит; беремо з глобальних масивів, якщо не передано
     */
    public function __construct(Config $config, Db $db = null, Request $request = null)
    {
        $this->config = $config;
        $this->debug = Debug::fromConfig($config);
        $this->db = $db === null ? new Db((array) $config->get('db', [])) : $db;
        $this->db->watch($this->debug);
        $this->url = Url::fromConfig($config);
        $this->request = $request === null
            ? Request::fromGlobals($this->url->base())
            : $request;
    }

    /**
     * Обробляє запит і друкує сторінку.
     *
     * @return void
     */
    public function run()
    {
        try {
            $this->debug->note(
                'запит',
                $this->request->method() . ' ' . $this->url->base() . $this->request->path()
            );

            $this->boot();

            // Перемикачі мови та валюти: ставимо куку й повертаємось на ту
            // саму сторінку вже без параметра.
            if ($this->request->hasQuery('lang') || $this->request->hasQuery('currency')) {
                $this->handlePreferences();

                return;
            }

            // Форма фільтра — перетворюємо в ЧПУ.
            if ($this->request->hasQuery(self::FILTER_FLAG)) {
                $this->handleFilterForm();

                return;
            }

            $first = (string) $this->request->segment(0, '');

            if (strpos($first, Url::OBJECT_PREFIX) === 0) {
                $this->showObject($first);

                return;
            }

            if ($first === Url::FAVORITES) {
                $this->showFavorites();

                return;
            }

            $this->showList();
        } catch (Exception $e) {
            $this->showError($e);
        }
    }

    /**
     * Готує все, що потрібно будь-якій сторінці.
     *
     * @return void
     */
    private function boot()
    {
        $this->lang = $this->resolveLang();
        $this->currency = $this->resolveCurrency();

        $this->debug->note('мова', $this->lang);
        $this->debug->note('валюта', $this->currency);

        $templates = $this->config->path('paths.templates');

        $this->t = new Translator($this->lang, $templates);
        $this->models = new ModelStore($this->config);
        $this->present = new Present(
            $this->config,
            $this->models,
            $this->t,
            $this->url,
            $this->lang,
            $this->currency,
            $this->firstSyncAt()
        );

        $this->search = new SearchRepository($this->db);
        $this->metro = new MetroRepository($this->db);

        $this->view = new View($templates, [
            't'        => $this->t,
            'url'      => $this->url,
            'present'  => $this->present,
            'config'   => $this->config,
            'lang'     => $this->lang,
            'currency' => $this->currency,
            'path'     => $this->request->path(),
        ]);

        // Сюда потрапляє й час автозавантаження Composer, читання конфігу та
        // текстів інтерфейсу: відлік ведеться від початку запиту.
        $this->debug->mark('підготовка (конфіг, тексти, обʼєкти)');
    }

    /**
     * Сторінка списку обʼєктів.
     *
     * @return void
     */
    private function showList()
    {
        $query = Query::fromSegments($this->request->segments(), $this->config, $this->currency);

        // Адреса непридатна: невідомий ключ фільтра або сміття у значенні.
        if ($query === null) {
            $this->showNotFound();

            return;
        }

        // Канонічна адреса: та сама вибірка мусить мати одну адресу.
        if ($this->url->searchPath($query) !== $this->request->path()) {
            $this->redirect($this->url->search($query), 301);

            return;
        }

        $this->debug->mark('розбір адреси');

        $result = $this->search->search($query);

        $this->debug->mark('вибірка обʼєктів');
        $this->debug->note('знайдено', $result['total']);
        $this->debug->note('на сторінці', count($result['rows']));

        // Сторінки з таким номером не існує — це саме 404, а не порожній
        // список: інакше пошуковик індексував би нескінченну пагінацію.
        if ($result['rows'] === [] && $query->page() > 1) {
            $this->showNotFound();

            return;
        }

        $title = $this->config->get('site.title' . ucfirst($this->lang), '');

        $facets = $this->facets($query);

        $this->debug->mark('списки фільтра');

        $content = $this->view->render('list', [
            'query'   => $query,
            'objects' => $result['rows'],
            'total'   => $result['total'],
            'pages'   => $result['pages'],
            'page'    => $result['page'],
            'facets'  => $facets,
            'title'   => $title,
        ]);

        $this->debug->mark('шаблон списку');

        $this->send($this->layout($content, $title, 'list'));
    }

    /**
     * Сторінка однієї публікації.
     *
     * @param string $segment Частина адреси вигляду 'object-6899c00963679e68c44d93c7'
     *
     * @return void
     */
    private function showObject($segment)
    {
        // Крім самої публікації в адресі більше нічого бути не може.
        if (count($this->request->segments()) > 1) {
            $this->showNotFound();

            return;
        }

        $publicationId = substr($segment, strlen(Url::OBJECT_PREFIX));

        // Ідентифікатори CRM — це 24 шістнадцяткові символи. Усе інше навіть
        // не питаємо в базі.
        if (!preg_match('~^[A-Za-z0-9]{1,24}$~', $publicationId)) {
            $this->showNotFound();

            return;
        }

        $object = $this->search->publication($publicationId);

        $this->debug->mark('публікація з бази');

        if ($object === null) {
            $this->showNotFound();

            return;
        }

        // У заголовку сторінки — повна назва обʼєкта, разом із кількістю
        // кімнат: «Продаж 3к будинку». У картці списку вона коротша.
        $title = $this->present->fullTitle($object);
        $address = $this->present->address($object);

        $similar = $this->search->similar($object);

        $this->debug->mark('схожі обʼєкти');

        $content = $this->view->render('object', [
            'object'  => $object,
            'agent'   => $object['agent'],
            'similar' => $similar,
            'title'   => $title,
            'address' => $address,
        ]);

        $this->debug->mark('шаблон публікації');

        $this->send($this->layout(
            $content,
            $address === null ? $title : $title . ', ' . $address,
            'object'
        ));
    }

    /**
     * Розділ «Обране».
     *
     * Список обраного зберігається в браузері відвідувача (localStorage), а
     * не на сервері: реєстрації в розділі немає, а зберігати вибір анонімного
     * відвідувача на сервері означало б ставити йому ще одну куку й
     * відповідати за ці дані.
     *
     * Тому сторінка працює у два кроки:
     *
     *   1. звичайний запит '/base/favorites/' віддає порожній каркас
     *      сторінки — сервер ще не знає, що саме відклав відвідувач;
     *   2. скрипт читає список із localStorage і запитує
     *      '/base/favorites/?ids=...' — а сюди сервер віддає вже готову
     *      верстку карточок, без шапки й підвалу.
     *
     * Карточки в обох випадках рендерить той самий partial, що й у списку
     * обʼєктів: користувач, який переробив картку під свій дизайн, отримує
     * її і тут.
     *
     * @return void
     */
    private function showFavorites()
    {
        // Крім самого розділу в адресі більше нічого бути не може: список
        // лежить у браузері, тому ні фільтрів, ні сторінок тут не буває.
        if (count($this->request->segments()) > 1) {
            $this->showNotFound();

            return;
        }

        $title = $this->t->get('favorites.title');

        // Крок 2: скрипт прислав список ідентифікаторів — віддаємо фрагмент.
        if ($this->request->hasQuery('ids')) {
            $this->sendFavoriteCards();

            return;
        }

        $content = $this->view->render('favorites', [
            'title' => $title,
        ]);

        $this->send($this->layout($content, $title, 'favorites'));
    }

    /**
     * Віддає верстку карточок обраного — відповідь на запит скрипта.
     *
     * @return void
     */
    private function sendFavoriteCards()
    {
        $ids = $this->request->get('ids', '');
        $ids = is_array($ids) ? [] : explode(',', (string) $ids);

        $objects = $this->search->byIds($ids);

        $this->debug->mark('обране з бази');
        $this->debug->note('обраних', count($objects));

        $html = $this->view->render('partials/cards', [
            'objects' => $objects,
            // Скрипту потрібно знати, скільки ID більше не знайшлося: такі
            // обʼєкти він прибере з localStorage, щоб список не ріс вічно.
            'found'   => array_map(function (array $row) {
                return $row['objectPublicationId'];
            }, $objects),
        ]);

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');

            // Це відповідь під конкретний браузер, і в пошуковий індекс їй
            // не місце: у ній немає ні шапки сайту, ні адреси, за якою її
            // можна відкрити повторно.
            header('X-Robots-Tag: noindex');
            header('Cache-Control: no-store');
        }

        $this->debug->mark('шаблон карточок');

        echo $html . $this->debug->summary();
    }

    /**
     * Час першого прогону синхронізації.
     *
     * Потрібен позначці «NEW»: перший прогін наповнює базу цілком, і всі
     * обʼєкти агентства отримують однаковий час появи в базі.
     *
     * Помилку читання глушимо: сторінка обʼєктів мусить відкритись навіть
     * тоді, коли таблиця syncState ще не створена.
     *
     * @return string|null
     */
    private function firstSyncAt()
    {
        try {
            $value = (new StateStore($this->db))->get(StateStore::FIRST_SYNC_AT);
        } catch (Exception $e) {
            return null;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Списки значень для фільтра.
     *
     * Усе беремо з бази: показувати у фільтрі те, чого в базі немає, — це
     * обіцяти відвідувачу результат, якого не буде. Якщо в списку менше двох
     * значень, шаблон його просто не показує.
     *
     * @param Query $query Поточний запит
     *
     * @return array
     */
    private function facets(Query $query)
    {
        $statuses = $query->statuses();
        $cityIds = (array) $query->filter('cityId', []);

        return [
            'operation' => $this->search->distinct('operation', $statuses),
            'type'      => $this->search->distinct('type', $statuses),
            'rooms'     => $this->search->distinct('rooms', $statuses),

            // Ознака «весь обʼєкт / частина» і новобудова — теж списки:
            // якщо в базі всі обʼєкти продаються цілком, поле «Обʼєкт» у
            // фільтрі показувати нічого.
            'whole'         => $this->search->distinct('whole', $statuses),
            'newBuilding'   => $this->search->distinct('newBuilding', $statuses),
            'commissioning' => $this->search->distinct('commissioning', $statuses),

            // Уточнення типу нерухомості. Кожне поле заповнене лише в
            // обʼєктів свого типу, тому шаблон показує його за вибраним
            // типом — а тут ми лише рахуємо, що взагалі є в базі.
            'commerceType' => $this->search->distinct('commerceType', $statuses),
            'landType'     => $this->search->distinct('landType', $statuses),
            'parkingType'  => $this->search->distinct('parkingType', $statuses),

            'cities'    => $this->search->cities($statuses),

            // Райони й станції метро показуємо для вибраних міст: інакше в
            // списку виявиться «Шевченківський район» від трьох різних міст.
            'districts' => $this->search->districts($statuses, $cityIds),
            'metro'     => $this->metro->stations($cityIds, $statuses),
        ];
    }

    /**
     * Приймає форму фільтра й перекидає на ЧПУ.
     *
     * Значення, які не пройшли перевірку, просто відкидаються: людина, яка
     * заповнює форму, не повинна отримати 404 через зайвий символ у полі
     * «ціна від».
     *
     * @return void
     */
    private function handleFilterForm()
    {
        $query = Query::fromConfig($this->config, $this->currency);
        $input = $this->request->query();

        foreach (Filters::keys() as $key) {
            $value = $this->filterFromInput($key, $input);

            if ($value !== null) {
                $query = $query->with($key, $value);
            }
        }

        if (isset($input[Query::KEY_SORT])) {
            $query = $query->withSort((string) $input[Query::KEY_SORT]);
        }

        $this->redirect($this->url->search($query), 302);
    }

    /**
     * Дістає значення одного фільтра з параметрів форми.
     *
     * Поля форми названі так:
     *
     *   type[]                 перелік значень (кілька однакових полів);
     *   priceUsdFrom, priceUsdTo   межі діапазону;
     *   metroDistance          одне число.
     *
     * @param string $key   Ключ фільтра
     * @param array  $input Параметри запиту
     *
     * @return array|int|null
     */
    private function filterFromInput($key, array $input)
    {
        $definition = Filters::definition($key);

        if ($definition === null) {
            return null;
        }

        if ($definition['kind'] === 'range') {
            $from = isset($input[$key . 'From']) ? $this->scalar($input[$key . 'From']) : '';
            $to = isset($input[$key . 'To']) ? $this->scalar($input[$key . 'To']) : '';

            // Кома як десятковий розділювач: саме її набирають з української
            // розкладки клавіатури.
            $from = str_replace([' ', ','], ['', '.'], $from);
            $to = str_replace([' ', ','], ['', '.'], $to);

            if ($from === '' && $to === '') {
                return null;
            }

            return Filters::parse($key, $from . '_' . $to);
        }

        if (!isset($input[$key])) {
            return null;
        }

        $raw = $input[$key];

        if (is_array($raw)) {
            $parts = [];

            foreach ($raw as $one) {
                $one = $this->scalar($one);

                if ($one !== '') {
                    $parts[] = $one;
                }
            }

            $raw = implode(',', $parts);
        } else {
            $raw = $this->scalar($raw);
        }

        return $raw === '' ? null : Filters::parse($key, $raw);
    }

    /**
     * Запамʼятовує вибрані мову та валюту в куках.
     *
     * @return void
     */
    private function handlePreferences()
    {
        $lang = (string) $this->request->get('lang', '');
        $currency = (string) $this->request->get('currency', '');

        if (in_array($lang, $this->config->languages(), true)) {
            $this->setCookie(self::COOKIE_LANG, $lang);
        }

        if (in_array($currency, $this->config->currencies(), true)) {
            $this->setCookie(self::COOKIE_CURRENCY, $currency);
        }

        // Повертаємось на ту саму сторінку. Діапазон ціни з адреси при зміні
        // валюти прибирається: перерахувати «від 50 000 доларів» у гривні ми
        // не можемо — курсу нам ніхто не передавав.
        $path = $this->request->path();

        if ($currency !== '') {
            $query = Query::fromSegments($this->request->segments(), $this->config, $this->currency);

            if ($query !== null) {
                $path = $this->url->searchPath($query->withCurrency($currency));
            }
        }

        $this->redirect($this->url->base() . $path, 302);
    }

    /**
     * Мова, якою показуємо сторінку.
     *
     * @return string
     */
    private function resolveLang()
    {
        $available = $this->config->languages();
        $cookie = (string) $this->request->cookie(self::COOKIE_LANG, '');

        if (in_array($cookie, $available, true)) {
            return $cookie;
        }

        return $this->config->defaultLanguage();
    }

    /**
     * Валюта, у якій показуємо ціни.
     *
     * Вибір відвідувача діє на всі публікації незалежно від типу операції,
     * тому від адреси нічого не залежить: або кука, або перша валюта зі
     * списку увімкнених.
     *
     * @return string
     */
    private function resolveCurrency()
    {
        $available = $this->config->currencies();
        $cookie = (string) $this->request->cookie(self::COOKIE_CURRENCY, '');

        if (in_array($cookie, $available, true)) {
            return $cookie;
        }

        return $this->config->defaultCurrency();
    }

    /**
     * Обгортає вміст сторінки у спільний шаблон.
     *
     * @param string $content Готовий HTML сторінки
     * @param string $title   Заголовок для <title>
     * @param string $page    Тип сторінки: list, object, favorites або other
     *
     * @return string
     */
    private function layout($content, $title, $page = 'other')
    {
        $siteTitle = (string) $this->config->get('site.title' . ucfirst($this->lang), '');

        $html = $this->view->render('layout', [
            'content'   => $content,
            'title'     => $title === '' ? $siteTitle : $title,
            'siteTitle' => $siteTitle,

            // Тип сторінки потрапляє в атрибут body: за ним site.js розуміє,
            // чи треба запамʼятати адресу пошуку для кнопки «назад».
            'page'      => $page,
        ]);

        $this->debug->mark('каркас сторінки');
        $this->debug->note('розмір html', strlen($html) . ' Б');

        return $html;
    }

    /**
     * Сторінка 404.
     *
     * @return void
     */
    private function showNotFound()
    {
        $content = $this->view->render('notFound');

        $this->send($this->layout($content, $this->t->get('error.notFound')), 404);
    }

    /**
     * Сторінка помилки.
     *
     * @param Exception $e Виключення
     *
     * @return void
     */
    private function showError(Exception $e)
    {
        $debug = (bool) $this->config->get('debug.enabled', false);

        // Текст помилки завжди пишемо в лог сервера: навіть коли відвідувачу
        // його не показуємо, розібратись потім треба буде саме за ним.
        error_log('PlusestSite: ' . $e->getMessage());

        // Шаблони могли не встигнути завантажитись — тоді віддаємо
        // найпростішу сторінку, зібрану тут же.
        if ($this->view === null || !$this->view->exists('error')) {
            $this->send(
                '<!doctype html><html lang="uk"><meta charset="utf-8">'
                . '<title>500</title><p>'
                . htmlspecialchars($debug ? $e->getMessage() : 'Сторінку не вдалося показати.', ENT_QUOTES)
                . '</p>',
                500
            );

            return;
        }

        $content = $this->view->render('error', [
            'message' => $debug ? $e->getMessage() : null,
            'trace'   => $debug ? $e->getTraceAsString() : null,
        ]);

        $this->send($this->layout($content, $this->t->get('error.general')), 500);
    }

    /**
     * Віддає готову сторінку.
     *
     * Сводка налагодження дописується саме тут — після всієї верстки, за
     * закритим </html>. Так вона не може нічого зламати у розкладці й
     * потрапляє у відповідь у тому числі зі сторінкою помилки.
     *
     * @param string $html Вміст
     * @param int    $code Код відповіді HTTP
     *
     * @return void
     */
    private function send($html, $code = 200)
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
        }

        $this->debug->note('код відповіді', $code);

        echo $html . $this->debug->summary();
    }

    /**
     * Перекидає браузер на іншу адресу.
     *
     * @param string $url  Куди
     * @param int    $code 301 (постійно) або 302 (тимчасово)
     *
     * @return void
     */
    private function redirect($url, $code = 302)
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Location: ' . $url);
        }

        // Тіло відповіді на редирект браузери не показують, але деякі
        // проксі-сервери його вимагають.
        echo '<!doctype html><html lang="uk"><meta charset="utf-8">'
            . '<meta http-equiv="refresh" content="0; url='
            . htmlspecialchars($url, ENT_QUOTES) . '">';
    }

    /**
     * Ставить куку на весь розділ.
     *
     * @param string $name  Назва
     * @param string $value Значення
     *
     * @return void
     */
    private function setCookie($name, $value)
    {
        if (headers_sent()) {
            return;
        }

        setcookie(
            $name,
            $value,
            time() + self::COOKIE_DAYS * 86400,
            $this->url->base(),
            '',
            // Захищену куку ставимо лише на https: інакше браузер її просто
            // не збереже, і перемикач мови працював би через раз.
            $this->isHttps(),
            false
        );
    }

    /**
     * Чи прийшов запит по https.
     *
     * @return bool
     */
    private function isHttps()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    }

    /**
     * Значення параметра форми у вигляді рядка.
     *
     * @param mixed $value Значення з $_GET
     *
     * @return string
     */
    private function scalar($value)
    {
        return is_array($value) ? '' : trim((string) $value);
    }
}

<?php

namespace Plusest\Site\Web;

/**
 * Поточний HTTP-запит.
 *
 * Обгортка над суперглобальними масивами: жоден інший клас сайту не читає
 * $_GET, $_SERVER і $_COOKIE напряму. Так, по-перше, легше зрозуміти, що
 * саме приходить іззовні, а по-друге — сторінку можна відрендерити в тестах,
 * підставивши потрібний шлях.
 *
 * Як визначається шлях
 * --------------------
 * Вебсервер переписує всі неіснуючі адреси на index.php, передаючи шлях у
 * параметрі plusestPath (див. docs/nginx.md):
 *
 *     rewrite ^/base/(.*)$ /base/index.php?plusestPath=$1 last;
 *
 * Якщо параметра немає (наприклад, користувач налаштував сервер інакше),
 * шлях беремо з REQUEST_URI і відрізаємо від нього базову адресу з конфігу.
 * Обидва шляхи ведуть до одного результату — масиву частин шляху.
 */
class Request
{
    /**
     * Назва параметра, у якому вебсервер передає шлях.
     */
    const PATH_PARAM = 'plusestPath';

    /**
     * Частини шляху після базової адреси.
     *
     * @var string[]
     */
    private $segments = [];

    /**
     * Параметри запиту ($_GET) без службового plusestPath.
     *
     * @var array
     */
    private $query = [];

    /**
     * Куки.
     *
     * @var array
     */
    private $cookies = [];

    /**
     * Метод запиту у верхньому регістрі.
     *
     * @var string
     */
    private $method = 'GET';

    /**
     * Створює запит із поточних суперглобальних масивів.
     *
     * @param string $base Базова адреса розділу з конфігу, напр. '/base/'
     *
     * @return self
     */
    public static function fromGlobals($base = '/')
    {
        $request = new self();

        $request->method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper((string) $_SERVER['REQUEST_METHOD'])
            : 'GET';

        $query = $_GET;
        $path = '';

        if (isset($query[self::PATH_PARAM])) {
            $path = (string) $query[self::PATH_PARAM];

            // Службовий параметр далі не потрібен: він не частина фільтрів.
            unset($query[self::PATH_PARAM]);
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $path = self::stripBase((string) $_SERVER['REQUEST_URI'], $base);
        }

        $request->segments = self::splitPath($path);
        $request->query = is_array($query) ? $query : [];
        $request->cookies = isset($_COOKIE) && is_array($_COOKIE) ? $_COOKIE : [];

        return $request;
    }

    /**
     * Створює запит із готових значень — для тестів і власних скриптів.
     *
     * @param string $path    Шлях після базової адреси
     * @param array  $query   Параметри запиту
     * @param array  $cookies Куки
     *
     * @return self
     */
    public static function make($path, array $query = [], array $cookies = [])
    {
        $request = new self();

        $request->segments = self::splitPath($path);
        $request->query = $query;
        $request->cookies = $cookies;

        return $request;
    }

    /**
     * Частини шляху після базової адреси.
     *
     * @return string[]
     */
    public function segments()
    {
        return $this->segments;
    }

    /**
     * Шлях після базової адреси у вигляді рядка із завершальним слешем.
     *
     * Потрібен для порівняння з канонічною адресою: якщо вони різні, сторінка
     * відповідає редиректом.
     *
     * @return string Наприклад 'operation-sale/page-2/' або '' для головної
     */
    public function path()
    {
        return $this->segments === [] ? '' : implode('/', $this->segments) . '/';
    }

    /**
     * Частина шляху за номером.
     *
     * @param int         $index   Номер, від 0
     * @param string|null $default Значення за замовчуванням
     *
     * @return string|null
     */
    public function segment($index, $default = null)
    {
        return isset($this->segments[$index]) ? $this->segments[$index] : $default;
    }

    /**
     * Усі параметри запиту.
     *
     * @return array
     */
    public function query()
    {
        return $this->query;
    }

    /**
     * Значення параметра запиту.
     *
     * Масиви повертаються як масиви (у формі фільтра поля названі `type[]`),
     * усе інше — рядком.
     *
     * @param string $name    Назва параметра
     * @param mixed  $default Значення за замовчуванням
     *
     * @return mixed
     */
    public function get($name, $default = null)
    {
        if (!isset($this->query[$name])) {
            return $default;
        }

        $value = $this->query[$name];

        return is_array($value) ? $value : (string) $value;
    }

    /**
     * Чи є в запиті такий параметр.
     *
     * @param string $name Назва параметра
     *
     * @return bool
     */
    public function hasQuery($name)
    {
        return isset($this->query[$name]);
    }

    /**
     * Значення куки.
     *
     * @param string $name    Назва
     * @param mixed  $default Значення за замовчуванням
     *
     * @return string|mixed
     */
    public function cookie($name, $default = null)
    {
        return isset($this->cookies[$name]) && !is_array($this->cookies[$name])
            ? (string) $this->cookies[$name]
            : $default;
    }

    /**
     * Метод запиту.
     *
     * @return string
     */
    public function method()
    {
        return $this->method;
    }

    /**
     * Відрізає від адреси базовий шлях розділу.
     *
     * @param string $uri  Значення REQUEST_URI
     * @param string $base Базова адреса з конфігу
     *
     * @return string Шлях відносно базової адреси
     */
    private static function stripBase($uri, $base)
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $base = '/' . trim((string) $base, '/');

        if ($base !== '/' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }

        // Запит міг прийти напряму на index.php — тоді назва скрипта в шляху
        // нам не потрібна.
        return preg_replace('~(^|/)index\.php$~', '', $path);
    }

    /**
     * Розбиває шлях на частини, відкидаючи порожні.
     *
     * @param string $path Шлях
     *
     * @return string[]
     */
    private static function splitPath($path)
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');

        if ($path === '') {
            return [];
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            $segment = rawurldecode($segment);

            // Порожні частини (подвійний слеш) просто пропускаємо, а от
            // спроби вийти з каталогу — ні: такого шляху в нас не буває.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return [];
            }

            $segments[] = $segment;
        }

        return $segments;
    }
}

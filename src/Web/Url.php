<?php

namespace Plusest\Site\Web;

use Plusest\Site\Config;
use Plusest\Site\Search\Query;

/**
 * Складає адреси сторінок, фотографій і файлів оформлення.
 *
 * Усі посилання на сайті будуються тут — у шаблонах немає жодного склеєного
 * руками шляху. Причина: базова адреса розділу залежить від того, як
 * користувач налаштував вебсервер ('/base/', '/nerukhomist/' або '/' на
 * окремому домені), а фотографії взагалі можуть віддаватись із CDN.
 *
 * Формат адрес
 * ------------
 *     {base}                                     список обʼєктів
 *     {base}operation-sale/type-house/page-2/    список із фільтром
 *     {base}object-6899c00963679e68c44d93c7/     сторінка публікації
 *     {photos}objects/{publicationId}/{photoId}-{size}.jpg
 *     {photos}staff/{agentId}/{photoId}.jpg
 */
class Url
{
    /**
     * Префікс адреси сторінки публікації.
     *
     * Такий самий формат «ключ-значення», як у фільтрів: одне правило на всі
     * адреси розділу.
     */
    const OBJECT_PREFIX = 'object-';

    /**
     * Частина адреси розділу «Обране».
     *
     * Без пари «ключ-значення», бо це не фільтр, а окрема сторінка: сам
     * список обраного лежить у браузері відвідувача, а не в адресі.
     */
    const FAVORITES = 'favorites';

    /**
     * Базова адреса розділу із завершальним слешем.
     *
     * @var string
     */
    private $base;

    /**
     * Базова адреса каталогу з фотографіями.
     *
     * @var string
     */
    private $photos;

    /**
     * @param string $base   Значення urls.base з конфігу
     * @param string $photos Значення urls.photos з конфігу
     */
    public function __construct($base = '/', $photos = '/photos/')
    {
        $this->base = $this->normalize($base);
        $this->photos = $this->normalize($photos);
    }

    /**
     * Створює побудовник із налаштувань.
     *
     * @param Config $config Налаштування
     *
     * @return self
     */
    public static function fromConfig(Config $config)
    {
        return new self(
            $config->get('urls.base', '/'),
            $config->get('urls.photos', '/photos/')
        );
    }

    /**
     * Базова адреса розділу.
     *
     * @return string
     */
    public function base()
    {
        return $this->base;
    }

    /**
     * Адреса списку обʼєктів для вказаного запиту.
     *
     * @param Query|null $query Запит; null — список без жодного фільтра
     *
     * @return string
     */
    public function search(Query $query = null)
    {
        if ($query === null) {
            return $this->base;
        }

        $segments = $query->segments();

        return $segments === [] ? $this->base : $this->base . implode('/', $segments) . '/';
    }

    /**
     * Шлях списку обʼєктів без базової адреси.
     *
     * Потрібен, щоб порівняти адресу, яку відкрив відвідувач, із канонічною.
     *
     * @param Query $query Запит
     *
     * @return string
     */
    public function searchPath(Query $query)
    {
        $segments = $query->segments();

        return $segments === [] ? '' : implode('/', $segments) . '/';
    }

    /**
     * Адреса сторінки публікації.
     *
     * @param string $publicationId ID публікації
     *
     * @return string
     */
    public function object($publicationId)
    {
        return $this->base . self::OBJECT_PREFIX . rawurlencode((string) $publicationId) . '/';
    }

    /**
     * Адреса розділу «Обране».
     *
     * @return string
     */
    public function favorites()
    {
        return $this->base . self::FAVORITES . '/';
    }

    /**
     * Адреса фотографії обʼєкта.
     *
     * @param string $publicationId ID публікації
     * @param string $photoId       ID фотографії з фіда
     * @param string $size          Назва розміру з photos.sizes
     *
     * @return string
     */
    public function photo($publicationId, $photoId, $size = 'medium')
    {
        return $this->photos . 'objects/'
            . $this->safe($publicationId) . '/'
            . $this->safe($photoId) . '-' . $this->safe($size) . '.jpg';
    }

    /**
     * Адреса фотографії співробітника.
     *
     * Розміру в назві файлу немає: фотографія співробітника зберігається
     * одним файлом такою, як прийшла зі сховища CRM.
     *
     * @param string $agentId ID співробітника
     * @param string $photoId ID фотографії з фіда
     *
     * @return string
     */
    public function staffPhoto($agentId, $photoId)
    {
        return $this->photos . 'staff/'
            . $this->safe($agentId) . '/'
            . $this->safe($photoId) . '.jpg';
    }

    /**
     * Адреса файлу оформлення з каталогу public/assets.
     *
     * @param string $file Назва файлу, напр. 'site.css'
     *
     * @return string
     */
    public function asset($file)
    {
        return $this->base . 'assets/' . ltrim((string) $file, '/');
    }

    /**
     * Адреса з доданими параметрами запиту.
     *
     * Так будуються перемикачі мови та валюти: вони не змінюють сторінку, а
     * лише передають вибір, тому це параметр, а не частина шляху.
     *
     * @param string $url    Адреса
     * @param array  $params Параметри
     *
     * @return string
     */
    public function withParams($url, array $params)
    {
        if ($params === []) {
            return $url;
        }

        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . http_build_query($params);
    }

    /**
     * Посилання на Google Maps за координатами обʼєкта.
     *
     * Своєї карти на сторінці немає навмисно: будь-яка вбудована карта — це
     * сторонній JavaScript, згода на обробку даних і помітне сповільнення
     * сторінки. Посилання дає той самий результат безкоштовно.
     *
     * @param float|null $lat  Широта
     * @param float|null $lon  Довгота
     * @param string     $lang Мова інтерфейсу карти
     *
     * @return string|null null, якщо координат немає
     */
    public function map($lat, $lon, $lang = 'uk')
    {
        if ($lat === null || $lon === null || $lat === '' || $lon === '') {
            return null;
        }

        return 'https://maps.google.com/?' . http_build_query([
            'hl' => $lang,
            'q'  => rtrim(rtrim(number_format((float) $lat, 8, '.', ''), '0'), '.')
                . ',' . rtrim(rtrim(number_format((float) $lon, 8, '.', ''), '0'), '.'),
        ]);
    }

    /**
     * Приводить базову адресу до вигляду '/шлях/'.
     *
     * Повні адреси з http:// залишаємо як є — так фотографії можна віддавати
     * з іншого домену або CDN.
     *
     * @param string $url Адреса з конфігу
     *
     * @return string
     */
    private function normalize($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '/';
        }

        if (preg_match('~^https?://~i', $url)) {
            return rtrim($url, '/') . '/';
        }

        return '/' . trim($url, '/') . '/';
    }

    /**
     * Прибирає з частини адреси все, що не може бути в назві файлу.
     *
     * Ідентифікатори приходять із бази, але шлях до файлу — не те місце, де
     * можна довіряти вхідним даним.
     *
     * @param string $value Значення
     *
     * @return string
     */
    private function safe($value)
    {
        return preg_replace('~[^A-Za-z0-9_-]~', '', (string) $value);
    }
}

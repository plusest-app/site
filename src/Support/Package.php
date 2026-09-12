<?php

namespace Plusest\Site\Support;

/**
 * Шляхи всередині самого пакета.
 *
 * Потрібен тому, що пакет може лежати у двох різних місцях:
 *   * vendor/plusest/site/ — якщо встановлений через Composer;
 *   * поруч із проєктом — якщо розпакований із ZIP-архіву.
 *
 * Замість того, щоб у кожному класі вважати кількість dirname(), питаємо
 * шляхи тут.
 */
class Package
{
    /**
     * Корінь пакета — каталог, у якому лежить composer.json.
     *
     * @return string
     */
    public static function root()
    {
        // __DIR__ це src/Support, отже два рівні вгору — корінь пакета.
        return dirname(dirname(__DIR__));
    }

    /**
     * Каталог із ресурсами пакета: шаблон конфігу тощо.
     *
     * Ці файли НЕ копіюються в проєкт користувача — вони потрібні самому
     * пакету для роботи.
     *
     * @param string|null $append Що додати до шляху
     *
     * @return string
     */
    public static function resources($append = null)
    {
        return Fs::join(self::root(), 'resources', $append);
    }

    /**
     * Каталог із заготовками, які публікуються в проєкт користувача:
     * схема бази, шаблони сторінок, скрипти запуску.
     *
     * @param string|null $append Що додати до шляху
     *
     * @return string
     */
    public static function stubs($append = null)
    {
        return Fs::join(self::root(), 'stubs', $append);
    }

    /**
     * Шлях до шаблону, з якого генерується config.php.
     *
     * Розширення .stub, а не .php, навмисно: у шаблоні замість значень стоять
     * токени {{...}}, тому як PHP-код він не парситься. Так його не зачепить
     * ні лінтер, ні випадковий запит із браузера.
     *
     * @return string
     */
    public static function configTemplate()
    {
        return self::resources('config.template.php.stub');
    }
}

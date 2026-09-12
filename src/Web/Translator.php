<?php

namespace Plusest\Site\Web;

use Plusest\Site\Support\Fs;

/**
 * Тексти інтерфейсу двома мовами.
 *
 * Тут лежить лише те, що не приходить із CRM: підписи кнопок, заголовки
 * блоків, повідомлення про порожній результат. Назви полів обʼєкта і значень
 * (тип нерухомості, вид опалення) беруться з моделей даних — див. Attribute.
 *
 * Як змінити текст під свій сайт
 * ------------------------------
 * Правити цей файл не потрібно: він у пакеті й перезапишеться при оновленні.
 * Створіть у каталозі шаблонів файл lang.php, який повертає масив лише з
 * тими рядками, які хочете змінити:
 *
 *     <?php
 *     return [
 *         'uk' => ['object.agent' => 'Ваш персональний менеджер'],
 *         'ru' => ['object.agent' => 'Ваш персональный менеджер'],
 *     ];
 *
 * Підстановки
 * -----------
 * У рядках зустрічаються заповнювачі вигляду :count — вони замінюються
 * значеннями з другого аргументу t():
 *
 *     $t->get('list.found', ['count' => 42]);
 *
 * Множина
 * -------
 * Українська й російська мають три форми числа, тому такі рядки записані
 * через вертикальну риску — «одна|дві|пʼять»:
 *
 *     'unit.rooms' => 'кімната|кімнати|кімнат',
 *
 *     $t->plural('unit.rooms', 3);   // 'кімнати'
 *
 * Назва обʼєкта
 * -------------
 * Ключі з префіксом `name.` — це слова для складання назви обʼєкта
 * («Продаж частини 3к будинку»). Вони стоять у фразі в родовому відмінку,
 * тому взяти їх із моделі даних не можна: там значення в називному
 * («будинок»). Складає назву Present::title(), а тут лежать самі слова —
 * щоб користувач міг замінити будь-яке з них у своєму lang.php.
 */
class Translator
{
    /**
     * Назва файлу з власними текстами в каталозі шаблонів.
     */
    const OVERRIDE_FILE = 'lang.php';

    /**
     * Поточна мова.
     *
     * @var string
     */
    private $lang;

    /**
     * Тексти поточної мови.
     *
     * @var array
     */
    private $strings = [];

    /**
     * @param string      $lang         Мова: 'uk' або 'ru'
     * @param string|null $templatesDir Каталог шаблонів, де може лежати lang.php
     */
    public function __construct($lang = 'uk', $templatesDir = null)
    {
        $this->lang = in_array($lang, ['uk', 'ru'], true) ? $lang : 'uk';

        $defaults = self::defaults();
        $strings = isset($defaults[$this->lang]) ? $defaults[$this->lang] : $defaults['uk'];

        if ($templatesDir !== null) {
            $strings = $this->applyOverrides($strings, $templatesDir);
        }

        $this->strings = $strings;
    }

    /**
     * Текст за ключем.
     *
     * Невідомий ключ повертається як є — так одразу видно, чого не вистачає,
     * і сторінка не падає через друкарську помилку в шаблоні.
     *
     * @param string $key     Ключ тексту
     * @param array  $replace Значення для заповнювачів вигляду :name
     *
     * @return string
     */
    public function get($key, array $replace = [])
    {
        $text = isset($this->strings[$key]) ? $this->strings[$key] : $key;

        // Довші назви заповнювачів замінюємо першими. Інакше :floor,
        // замінений раніше, зʼїв би початок :floors — і в тексті
        // «поверх :floor з :floors» замість числа лишилась би літера «s».
        uksort($replace, function ($first, $second) {
            return strlen($second) - strlen($first);
        });

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Чи є такий текст у перекладах.
     *
     * Потрібно там, де ключ складається зі значення з CRM
     * (`name.commerceType.office`): невідоме значення мусить дати не сам
     * ключ у заголовку обʼєкта, а загальну назву типу нерухомості.
     *
     * @param string $key Ключ тексту
     *
     * @return bool
     */
    public function has($key)
    {
        return isset($this->strings[$key]);
    }

    /**
     * Текст у формі, потрібній для вказаного числа.
     *
     * Рядок у перекладах записаний трьома формами через вертикальну риску:
     * «1 кімната | 2 кімнати | 5 кімнат». Правило вибору однакове для
     * української та російської мов.
     *
     * @param string $key   Ключ тексту
     * @param int    $count Число, для якого потрібна форма
     *
     * @return string Лише слово, без самого числа
     */
    public function plural($key, $count)
    {
        $forms = explode('|', $this->get($key));

        // Ключа немає в перекладах або він записаний однією формою — тоді
        // повертаємо як є: краще неправильний відмінок, ніж порожнє місце.
        if (count($forms) < 3) {
            return $forms[0];
        }

        $count = abs((int) $count);
        $tens = $count % 100;
        $units = $count % 10;

        if ($units === 1 && $tens !== 11) {
            return $forms[0];
        }

        if ($units >= 2 && $units <= 4 && ($tens < 10 || $tens >= 20)) {
            return $forms[1];
        }

        return $forms[2];
    }

    /**
     * Число разом зі словом у потрібній формі: «3 кімнати».
     *
     * @param string $key   Ключ тексту з формами
     * @param int    $count Число
     *
     * @return string
     */
    public function counted($key, $count)
    {
        // Нерозривний пробіл: «3 кімнати» не мусить розриватись на два рядки.
        return (int) $count . "\xC2\xA0" . $this->plural($key, $count);
    }

    /**
     * Поточна мова.
     *
     * @return string
     */
    public function lang()
    {
        return $this->lang;
    }

    /**
     * Усі тексти поточної мови — знадобиться, якщо ви віддаєте їх у JavaScript.
     *
     * @return array
     */
    public function all()
    {
        return $this->strings;
    }

    /**
     * Тексти, що постачаються з пакетом.
     *
     * @return array Масив «мова => [ключ => текст]»
     */
    public static function defaults()
    {
        return [
            'uk' => [
                // --- Загальне ------------------------------------------------
                'site.objects'        => 'обʼєкти',
                'common.all'          => 'усі',
                'common.from'         => 'від',
                'common.to'           => 'до',
                'common.yes'          => 'так',
                'common.no'           => 'ні',
                'common.more'         => 'детальніше',
                'common.close'        => 'закрити',
                'common.meters'       => 'м',
                'common.any'          => 'не важливо',

                // --- Множина -------------------------------------------------
                //  Три форми через риску: «1 кімната | 2 кімнати | 5 кімнат».
                'unit.rooms'    => 'кімната|кімнати|кімнат',
                'unit.premises' => 'приміщення|приміщення|приміщень',
                'unit.objects'  => 'обʼєкт|обʼєкти|обʼєктів',
                'unit.photos'   => 'фотографія|фотографії|фотографій',

                // --- Фільтр --------------------------------------------------
                'filter.title'         => 'Фільтр',
                'filter.apply'         => 'Показати',
                'filter.reset'         => 'Скинути',
                'filter.more'          => 'Більше параметрів',
                'filter.moreClose'     => 'Згорнути',
                'filter.whole'         => 'Обʼєкт',
                'filter.wholeFull'     => 'Весь обʼєкт',
                'filter.wholePart'     => 'Частина обʼєкта',
                'filter.priceFrom'     => 'Ціна від',
                'filter.priceTo'       => 'Ціна до',

                // Тексти всередині випадних списків Select2. Передаються в
                // JavaScript — див. кінець layout.php.
                'select.noResults'   => 'Нічого не знайдено',
                'select.searching'   => 'Шукаємо...',
                'filter.city'          => 'Населений пункт',
                'filter.district'      => 'Район міста',
                'filter.metro'         => 'Метро',
                'filter.metroDistance' => 'Не далі ніж',
                'filter.price'         => 'Ціна',
                'filter.area'          => 'Площа, м²',
                'filter.areaLand'      => 'Ділянка, сотих',
                'filter.floor'         => 'Поверх',
                'filter.floors'        => 'Поверхів у будинку',
                'filter.rooms'         => 'Кімнат',
                'filter.selected'      => 'Вибрано фільтрів: :count',

                // --- Сортування ----------------------------------------------
                'sort.title'     => 'Сортування',
                'sort.new'       => 'спочатку нові',
                'sort.old'       => 'спочатку давні',
                'sort.priceUp'   => 'ціна: від дешевих',
                'sort.priceDown' => 'ціна: від дорогих',
                'sort.areaUp'    => 'площа: від меншої',
                'sort.areaDown'  => 'площа: від більшої',

                // --- Список --------------------------------------------------
                'list.found'      => 'Знайдено обʼєктів: :count',
                'list.empty'      => 'За такими умовами обʼєктів немає',
                'list.emptyHint'  => 'Спробуйте прибрати частину фільтрів — можливо, умови занадто вузькі.',
                'list.nothingYet' => 'База обʼєктів ще порожня. Якщо ви щойно встановили розділ, дочекайтесь першої синхронізації з CRM.',

                // --- Пагінація -----------------------------------------------
                'pagination.prev' => 'Назад',
                'pagination.next' => 'Далі',
                'pagination.page' => 'Сторінка :page з :pages',

                // --- Картка обʼєкта ------------------------------------------
                'card.unit'     => 'за м²',
                'card.unitLand' => 'за соту',
                'card.sold'     => 'Реалізовано',
                'card.soldSale' => 'Продано',
                'card.soldRent' => 'Здано',
                'card.new'      => 'NEW',
                'card.noPhoto'  => 'Без фотографій',
                'card.floor'      => 'поверх :floor з :floors',
                'card.floorOnly'  => 'поверх :floor',
                'card.floorsOnly' => 'поверхів: :floors',
                'card.rooms'    => ':count кімн.',
                'card.area'     => ':value м²',
                'card.areaLand' => ':value сот.',
                'card.metro'    => ':name, :distance м',
                'card.perMonth' => 'на місяць',
                'card.details'  => 'Детальніше',
                'card.prev'     => 'Попереднє фото',
                'card.next'     => 'Наступне фото',

                // --- Обране --------------------------------------------------
                'favorites.title'    => 'Обране',
                'favorites.add'      => 'Додати в обране',
                'favorites.remove'   => 'Прибрати з обраного',
                'favorites.empty'    => 'В обраному ще нічого немає',
                'favorites.hint'     => 'Натисніть сердечко на картці обʼєкта — і він зʼявиться тут. Список зберігається у вашому браузері.',
                'favorites.loading'  => 'Завантажуємо обране...',
                'favorites.failed'   => 'Не вдалося завантажити обране. Спробуйте оновити сторінку.',
                'favorites.back'     => 'До обраного',
                'favorites.gone'     => 'Частину обʼєктів більше не знайдено — можливо, їх зняли з продажу.',
                'favorites.clear'    => 'Очистити список',

                // --- Назва обʼєкта -------------------------------------------
                //  Слова стоять у фразі в родовому відмінку: «Продаж частини
                //  3к будинку». Тому взяти їх із моделі даних не можна.
                'name.sale'  => 'Продаж',
                'name.rent'  => 'Оренда',
                'name.part'  => 'частини',
                'name.rooms' => ':countк',

                'name.type.apartment' => 'квартири',
                'name.type.house'     => 'будинку',
                'name.type.land'      => 'землі',
                'name.type.parking'   => 'автомісця',
                'name.type.commerce'  => 'комерції',

                'name.landType.a' => 'сільськогосподарського призначення',
                'name.landType.b' => 'житлової та громадської забудови',
                'name.landType.c' => 'природно-заповідного фонду',
                'name.landType.d' => 'оздоровчого призначення',
                'name.landType.e' => 'рекреаційного призначення',
                'name.landType.g' => 'історико-культурного призначення',
                'name.landType.h' => 'лісогосподарського призначення',
                'name.landType.i' => 'водного фонду',
                'name.landType.j' => 'промисловості, транспорту, звʼязку, енергетики, оборони',
                'name.landType.k' => 'запасу, резервного фонду та загального користування',

                'name.parkingType.garage'   => 'гаража',
                'name.parkingType.parking'  => 'місця на паркінгу',
                'name.parkingType.open-air' => 'місця на стоянці',
                'name.parkingType.box'      => 'боксу',
                'name.parkingType.hangar'   => 'ангару',

                'name.commerceType.premises'    => 'приміщення вільного призначення',
                'name.commerceType.office'      => 'офісного приміщення',
                'name.commerceType.manufacture' => 'виробничого приміщення',
                'name.commerceType.shop'        => 'торгової площі (магазину, павільйону)',
                'name.commerceType.warehouse'   => 'складського приміщення',
                'name.commerceType.restaurant'  => 'обʼєкта харчування (кафе, бар, ресторан)',
                'name.commerceType.hotel'       => 'обʼєкта відпочинку (готель, база, пансіонат)',
                'name.commerceType.medicine'    => 'обʼєкта медицини та фармакології',
                'name.commerceType.gym'         => 'спортзалу (тренажерного залу)',
                'name.commerceType.beauty'      => 'салону краси (перукарні)',
                'name.commerceType.fun'         => 'обʼєкта розваг (боулінг, більярд)',
                'name.commerceType.farmer'      => 'фермерського господарства',
                'name.commerceType.car'         => 'обʼєкта автосервісу (СТО, АЗС, автомийки)',
                'name.commerceType.building'    => 'окремо розташованої будівлі',
                'name.commerceType.saf'         => 'МАФу',
                'name.commerceType.other'       => 'комерції',

                // --- Сторінка обʼєкта ----------------------------------------
                'object.back'            => 'До списку обʼєктів',
                'object.parameters'      => 'Основні параметри',
                'object.characteristics' => 'Характеристики',
                'object.description'     => 'Опис',
                'object.address'         => 'Адреса',
                'object.map'             => 'Показати на карті',
                'object.metro'           => 'Метро поруч',
                'object.complex'         => 'Житловий комплекс',
                'object.multimedia'      => 'Відео та огляди',
                'object.agent'           => 'Ваш агент',
                'object.agentAsk'        => 'Зателефонуйте, щоб домовитись про перегляд',
                'object.similar'         => 'Схожі обʼєкти',
                'object.soldNotice'      => 'Цей обʼєкт уже реалізований. Ми підберемо для вас схожий — зателефонуйте нам.',
                'object.photos'          => 'Фотографії',
                'object.cadastre'        => 'Кадастровий номер',

                // Продаючий текст під карточкою агента. :name — імʼя агента,
                // а форма дієслова залежить від статі співробітника в CRM.
                'object.agentLeadMan'    => 'Сподобалась пропозиція? Зателефонуйте :name — він розповість про обʼєкт те, чого немає в оголошенні, і запише вас на перегляд у зручний час.',
                'object.agentLeadWoman'  => 'Сподобалась пропозиція? Зателефонуйте :name — вона розповість про обʼєкт те, чого немає в оголошенні, і запише вас на перегляд у зручний час.',
                'object.agentLead'       => 'Сподобалась пропозиція? Зателефонуйте — ми розповімо про обʼєкт те, чого немає в оголошенні, і запишемо вас на перегляд у зручний час.',
                'object.agentNote'       => 'Консультація безкоштовна, а перегляд можна призначити навіть на вихідні.',
                'object.soldNoticeSale'  => 'Цей обʼєкт уже продано',
                'object.soldNoticeRent'  => 'Цей обʼєкт уже здано',
                'object.gallery'         => 'Дивитись усі фотографії',
                'object.priceUnitFor'    => 'ціна :unit',
                'object.backTop'         => 'Повернутись до пошуку',

                // --- Помилки -------------------------------------------------
                'error.notFound'      => 'Сторінку не знайдено',
                'error.notFoundText'  => 'Такої сторінки немає. Можливо, обʼєкт зняли з продажу або в адресі є помилка.',
                'error.general'       => 'Щось пішло не так',
                'error.generalText'   => 'Сторінку не вдалося показати. Спробуйте оновити її трохи пізніше.',
                'error.notInstalled'  => 'Розділ нерухомості ще не налаштований.',
            ],

            'ru' => [
                // --- Общее ---------------------------------------------------
                'site.objects'        => 'объекты',
                'common.all'          => 'все',
                'common.from'         => 'от',
                'common.to'           => 'до',
                'common.yes'          => 'да',
                'common.no'           => 'нет',
                'common.more'         => 'подробнее',
                'common.close'        => 'закрыть',
                'common.meters'       => 'м',
                'common.any'          => 'не важно',

                // --- Множественное число -------------------------------------
                //  Три формы через черту: «1 комната | 2 комнаты | 5 комнат».
                'unit.rooms'    => 'комната|комнаты|комнат',
                'unit.premises' => 'помещение|помещения|помещений',
                'unit.objects'  => 'объект|объекта|объектов',
                'unit.photos'   => 'фотография|фотографии|фотографий',

                // --- Фильтр --------------------------------------------------
                'filter.title'         => 'Фильтр',
                'filter.apply'         => 'Показать',
                'filter.reset'         => 'Сбросить',
                'filter.more'          => 'Больше параметров',
                'filter.moreClose'     => 'Свернуть',
                'filter.whole'         => 'Объект',
                'filter.wholeFull'     => 'Весь объект',
                'filter.wholePart'     => 'Часть объекта',
                'filter.priceFrom'     => 'Цена от',
                'filter.priceTo'       => 'Цена до',

                // Тексты внутри выпадающих списков Select2. Передаются в
                // JavaScript — см. конец layout.php.
                'select.noResults'   => 'Ничего не найдено',
                'select.searching'   => 'Ищем...',
                'filter.city'          => 'Населённый пункт',
                'filter.district'      => 'Район города',
                'filter.metro'         => 'Метро',
                'filter.metroDistance' => 'Не дальше чем',
                'filter.price'         => 'Цена',
                'filter.area'          => 'Площадь, м²',
                'filter.areaLand'      => 'Участок, соток',
                'filter.floor'         => 'Этаж',
                'filter.floors'        => 'Этажей в доме',
                'filter.rooms'         => 'Комнат',
                'filter.selected'      => 'Выбрано фильтров: :count',

                // --- Сортировка ----------------------------------------------
                'sort.title'     => 'Сортировка',
                'sort.new'       => 'сначала новые',
                'sort.old'       => 'сначала давние',
                'sort.priceUp'   => 'цена: от дешёвых',
                'sort.priceDown' => 'цена: от дорогих',
                'sort.areaUp'    => 'площадь: от меньшей',
                'sort.areaDown'  => 'площадь: от большей',

                // --- Список --------------------------------------------------
                'list.found'      => 'Найдено объектов: :count',
                'list.empty'      => 'По таким условиям объектов нет',
                'list.emptyHint'  => 'Попробуйте убрать часть фильтров — возможно, условия слишком узкие.',
                'list.nothingYet' => 'База объектов ещё пуста. Если вы только что установили раздел, дождитесь первой синхронизации с CRM.',

                // --- Пагинация -----------------------------------------------
                'pagination.prev' => 'Назад',
                'pagination.next' => 'Далее',
                'pagination.page' => 'Страница :page из :pages',

                // --- Карточка объекта ----------------------------------------
                'card.unit'     => 'за м²',
                'card.unitLand' => 'за сотку',
                'card.sold'     => 'Реализован',
                'card.soldSale' => 'Продано',
                'card.soldRent' => 'Сдано',
                'card.new'      => 'NEW',
                'card.noPhoto'  => 'Без фотографий',
                'card.floor'      => 'этаж :floor из :floors',
                'card.floorOnly'  => 'этаж :floor',
                'card.floorsOnly' => 'этажей: :floors',
                'card.rooms'    => ':count комн.',
                'card.area'     => ':value м²',
                'card.areaLand' => ':value сот.',
                'card.metro'    => ':name, :distance м',
                'card.perMonth' => 'в месяц',
                'card.details'  => 'Подробнее',
                'card.prev'     => 'Предыдущее фото',
                'card.next'     => 'Следующее фото',

                // --- Избранное -----------------------------------------------
                'favorites.title'    => 'Избранное',
                'favorites.add'      => 'Добавить в избранное',
                'favorites.remove'   => 'Убрать из избранного',
                'favorites.empty'    => 'В избранном пока ничего нет',
                'favorites.hint'     => 'Нажмите сердечко на карточке объекта — и он появится здесь. Список хранится в вашем браузере.',
                'favorites.loading'  => 'Загружаем избранное...',
                'favorites.failed'   => 'Не удалось загрузить избранное. Попробуйте обновить страницу.',
                'favorites.back'     => 'К избранному',
                'favorites.gone'     => 'Часть объектов больше не найдена — возможно, их сняли с продажи.',
                'favorites.clear'    => 'Очистить список',

                // --- Название объекта ----------------------------------------
                //  Слова стоят во фразе в родительном падеже: «Продажа части
                //  3к дома». Поэтому взять их из модели данных нельзя.
                'name.sale'  => 'Продажа',
                'name.rent'  => 'Аренда',
                'name.part'  => 'части',
                'name.rooms' => ':countк',

                'name.type.apartment' => 'квартиры',
                'name.type.house'     => 'дома',
                'name.type.land'      => 'земли',
                'name.type.parking'   => 'автоместа',
                'name.type.commerce'  => 'коммерции',

                'name.landType.a' => 'сельскохозяйственного назначения',
                'name.landType.b' => 'жилой и общественной застройки',
                'name.landType.c' => 'природно-заповедного фонда',
                'name.landType.d' => 'оздоровительного назначения',
                'name.landType.e' => 'рекреационного назначения',
                'name.landType.g' => 'историко-культурного назначения',
                'name.landType.h' => 'лесохозяйственного назначения',
                'name.landType.i' => 'водного фонда',
                'name.landType.j' => 'промышленности, транспорта, связи, энергетики, обороны',
                'name.landType.k' => 'запаса, резервного фонда и общего пользования',

                'name.parkingType.garage'   => 'гаража',
                'name.parkingType.parking'  => 'места на паркинге',
                'name.parkingType.open-air' => 'места на стоянке',
                'name.parkingType.box'      => 'бокса',
                'name.parkingType.hangar'   => 'ангара',

                'name.commerceType.premises'    => 'помещения свободного назначения',
                'name.commerceType.office'      => 'офисного помещения',
                'name.commerceType.manufacture' => 'производственного помещения',
                'name.commerceType.shop'        => 'торговой площади (магазина, павильона)',
                'name.commerceType.warehouse'   => 'складского помещения',
                'name.commerceType.restaurant'  => 'объекта питания (кафе, бар, ресторан)',
                'name.commerceType.hotel'       => 'объекта отдыха (отель, база, пансионат)',
                'name.commerceType.medicine'    => 'объекта медицины и фармакологии',
                'name.commerceType.gym'         => 'спортзала (тренажерного зала)',
                'name.commerceType.beauty'      => 'салона красоты (парикмахерской)',
                'name.commerceType.fun'         => 'объекта развлечений (боулинг, бильярд)',
                'name.commerceType.farmer'      => 'фермерского хозяйства',
                'name.commerceType.car'         => 'объекта автообслуживания (СТО, АЗС, автомойка)',
                'name.commerceType.building'    => 'отдельностоящего здания',
                'name.commerceType.saf'         => 'МАФа',
                'name.commerceType.other'       => 'коммерции',

                // --- Страница объекта ----------------------------------------
                'object.back'            => 'К списку объектов',
                'object.parameters'      => 'Основные параметры',
                'object.characteristics' => 'Характеристики',
                'object.description'     => 'Описание',
                'object.address'         => 'Адрес',
                'object.map'             => 'Показать на карте',
                'object.metro'           => 'Метро рядом',
                'object.complex'         => 'Жилой комплекс',
                'object.multimedia'      => 'Видео и обзоры',
                'object.agent'           => 'Ваш агент',
                'object.agentAsk'        => 'Позвоните, чтобы договориться о просмотре',
                'object.similar'         => 'Похожие объекты',
                'object.soldNotice'      => 'Этот объект уже реализован. Мы подберём для вас похожий — позвоните нам.',
                'object.photos'          => 'Фотографии',
                'object.cadastre'        => 'Кадастровый номер',

                // Продающий текст под карточкой агента. :name — имя агента,
                // а форма глагола зависит от пола сотрудника в CRM.
                'object.agentLeadMan'    => 'Понравилось предложение? Позвоните :name — он расскажет об объекте то, чего нет в объявлении, и запишет вас на просмотр в удобное время.',
                'object.agentLeadWoman'  => 'Понравилось предложение? Позвоните :name — она расскажет об объекте то, чего нет в объявлении, и запишет вас на просмотр в удобное время.',
                'object.agentLead'       => 'Понравилось предложение? Позвоните — мы расскажем об объекте то, чего нет в объявлении, и запишем вас на просмотр в удобное время.',
                'object.agentNote'       => 'Консультация бесплатная, а просмотр можно назначить даже на выходные.',
                'object.soldNoticeSale'  => 'Этот объект уже продан',
                'object.soldNoticeRent'  => 'Этот объект уже сдан',
                'object.gallery'         => 'Смотреть все фотографии',
                'object.priceUnitFor'    => 'цена :unit',
                'object.backTop'         => 'Вернуться к поиску',

                // --- Ошибки --------------------------------------------------
                'error.notFound'      => 'Страница не найдена',
                'error.notFoundText'  => 'Такой страницы нет. Возможно, объект снят с продажи или в адресе ошибка.',
                'error.general'       => 'Что-то пошло не так',
                'error.generalText'   => 'Страницу не удалось показать. Попробуйте обновить её немного позже.',
                'error.notInstalled'  => 'Раздел недвижимости ещё не настроен.',
            ],
        ];
    }

    /**
     * Символ валюти.
     *
     * Не в масиві текстів: символи однакові для обох мов.
     *
     * @param string $currency 'usd', 'uah' або 'eur'
     *
     * @return string
     */
    public static function currencySymbol($currency)
    {
        $symbols = ['usd' => '$', 'uah' => '₴', 'eur' => '€'];
        $currency = strtolower((string) $currency);

        return isset($symbols[$currency]) ? $symbols[$currency] : strtoupper($currency);
    }

    /**
     * Назва мови для перемикача.
     *
     * @param string $lang Код мови
     *
     * @return string
     */
    public static function langName($lang)
    {
        $names = ['uk' => 'Укр', 'ru' => 'Рус'];

        return isset($names[$lang]) ? $names[$lang] : strtoupper((string) $lang);
    }

    /**
     * Накладає власні тексти користувача поверх наших.
     *
     * @param array  $strings      Наші тексти
     * @param string $templatesDir Каталог шаблонів
     *
     * @return array
     */
    private function applyOverrides(array $strings, $templatesDir)
    {
        $file = Fs::join($templatesDir, self::OVERRIDE_FILE);

        if (!is_file($file)) {
            return $strings;
        }

        /** @noinspection PhpIncludeInspection */
        $custom = require $file;

        if (!is_array($custom)) {
            return $strings;
        }

        // Файл може містити або обидві мови, або одразу плоский список
        // рядків — тоді вважаємо, що він для поточної мови.
        if (isset($custom[$this->lang]) && is_array($custom[$this->lang])) {
            return $custom[$this->lang] + $strings;
        }

        if (isset($custom['uk']) || isset($custom['ru'])) {
            return $strings;
        }

        return $custom + $strings;
    }
}

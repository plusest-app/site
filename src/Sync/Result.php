<?php

namespace Plusest\Site\Sync;

/**
 * Підсумки прогону синхронізації.
 *
 * Проста скарбничка з лічильниками. Використовується для трьох речей:
 * вивести звіт у термінал, записати в таблицю syncState і показати
 * на сторінці стану у веб-установнику.
 */
class Result
{
    /**
     * Лічильники: назва => кількість.
     *
     * @var array
     */
    private $counters = [
        // --- Обʼєкти -------------------------------------------------------
        'objectsInFeed'   => 0,
        'objectsCreated'  => 0,
        'objectsUpdated'  => 0,
        'objectsSkipped'  => 0,
        'objectsSold'     => 0,
        'objectsHidden'   => 0,
        'objectsDeleted'  => 0,

        // Публікації звільнених співробітників — видаляються безповоротно.
        'objectsFired'    => 0,

        // --- Співробітники --------------------------------------------------
        'staffInFeed'     => 0,
        'staffCreated'    => 0,
        'staffUpdated'    => 0,
        'staffSkipped'    => 0,
        'staffDeleted'    => 0,

        // --- Фотографії -----------------------------------------------------
        'photosDownloaded'          => 0,
        'photosStaffDownloaded'     => 0,
        'photosRemoved'             => 0,
        'photosFailed'              => 0,
        'photosDirsRemoved'         => 0,
        'photosPublicationsDone'    => 0,
        'photosPublicationsPending' => 0,
    ];

    /**
     * Повідомлення про помилки окремих обʼєктів.
     *
     * Один зіпсований обʼєкт не зупиняє прогін — помилка запамʼятовується
     * тут, і робота продовжується з наступним.
     *
     * @var string[]
     */
    private $errors = [];

    /**
     * Чи запитувався фід у цьому прогоні.
     *
     * Прогін, що припав на паузу між запитами до CRM, працює лише з
     * фотографіями. Без цієї позначки звіт написав би «обʼєктів у фіді 0»,
     * і користувач вирішив би, що з публікації в CRM зникли всі обʼєкти.
     *
     * @var bool
     */
    private $feedFetched = false;

    /**
     * Час початку прогону.
     *
     * @var float
     */
    private $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Збільшує лічильник.
     *
     * @param string $name Назва лічильника
     * @param int    $by   На скільки збільшити
     *
     * @return void
     */
    public function add($name, $by = 1)
    {
        if (!array_key_exists($name, $this->counters)) {
            $this->counters[$name] = 0;
        }

        $this->counters[$name] += (int) $by;
    }

    /**
     * Значення лічильника.
     *
     * @param string $name Назва
     *
     * @return int
     */
    public function get($name)
    {
        return isset($this->counters[$name]) ? $this->counters[$name] : 0;
    }

    /**
     * Запамʼятовує помилку окремого обʼєкта.
     *
     * @param string $message Текст помилки
     *
     * @return void
     */
    public function addError($message)
    {
        // Обмежуємо кількість: якщо фід зіпсований цілком, немає сенсу
        // тягнути в базу тисячі однакових повідомлень.
        if (count($this->errors) < 50) {
            $this->errors[] = (string) $message;
        }
    }

    /**
     * Помилки окремих обʼєктів.
     *
     * @return string[]
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Чи були помилки.
     *
     * @return bool
     */
    public function hasErrors()
    {
        return $this->errors !== [];
    }

    /**
     * Скільки часу тривав прогін, секунди.
     *
     * @return float
     */
    public function duration()
    {
        return round(microtime(true) - $this->startedAt, 2);
    }

    /**
     * Чи змінилося щось у базі за цей прогін.
     *
     * @return bool
     */
    public function hasChanges()
    {
        foreach (['objectsCreated', 'objectsUpdated', 'objectsSold', 'objectsHidden',
                  'objectsDeleted', 'objectsFired', 'staffCreated', 'staffUpdated',
                  'staffDeleted', 'photosDownloaded', 'photosStaffDownloaded',
                  'photosRemoved', 'photosDirsRemoved'] as $name) {
            if ($this->get($name) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Позначає, що фід у цьому прогоні запитували.
     *
     * @return void
     */
    public function markFeedFetched()
    {
        $this->feedFetched = true;
    }

    /**
     * Чи запитувався фід у цьому прогоні.
     *
     * @return bool
     */
    public function hasFeed()
    {
        return $this->feedFetched;
    }

    /**
     * Чи відбувалось щось із фотографіями за цей прогін.
     *
     * @return bool
     */
    public function photosTouched()
    {
        foreach (['photosDownloaded', 'photosStaffDownloaded', 'photosRemoved',
                  'photosFailed', 'photosDirsRemoved', 'photosPublicationsPending'] as $name) {
            if ($this->get($name) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Усі лічильники разом із часом виконання — для запису в syncState.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->counters + [
            'duration' => $this->duration(),
            'errors'   => count($this->errors),
        ];
    }

    /**
     * Короткий звіт у кілька рядків для термінала й логу.
     *
     * @return string[]
     */
    public function lines()
    {
        $lines = [];

        // Про обʼєкти та співробітників звітуємо лише коли фід справді
        // запитували: інакше нулі виглядали б як «у CRM нічого не лишилось».
        if ($this->feedFetched) {
            $lines[] = sprintf(
                'Обʼєкти: у фіді %d, додано %d, оновлено %d, без змін %d',
                $this->get('objectsInFeed'),
                $this->get('objectsCreated'),
                $this->get('objectsUpdated'),
                $this->get('objectsSkipped')
            );

            // Рядок про зниклі публікації показуємо лише коли вони справді були.
            $gone = $this->get('objectsSold') + $this->get('objectsHidden') + $this->get('objectsDeleted');

            if ($gone > 0) {
                $lines[] = sprintf(
                    'Зникли з фіда: позначено реалізованими %d, приховано %d, видалено %d',
                    $this->get('objectsSold'),
                    $this->get('objectsHidden'),
                    $this->get('objectsDeleted')
                );
            }

            // Видалення публікацій звільнених співробітників — подія, про яку
            // користувач мусить дізнатися: дані зникли безповоротно.
            if ($this->get('objectsFired') > 0) {
                $lines[] = sprintf(
                    'Видалено публікацій звільнених співробітників: %d',
                    $this->get('objectsFired')
                );
            }

            $lines[] = sprintf(
                'Співробітники: у фіді %d, додано %d, оновлено %d, без змін %d, видалено %d',
                $this->get('staffInFeed'),
                $this->get('staffCreated'),
                $this->get('staffUpdated'),
                $this->get('staffSkipped'),
                $this->get('staffDeleted')
            );
        }

        // Рядок про фотографії показуємо лише коли з ними щось відбувалось:
        // на звичайному прогоні, де все вже завантажено, він був би шумом.
        if ($this->photosTouched()) {
            $lines[] = sprintf(
                'Фотографії: завантажено %d, видалено файлів %d, помилок %d',
                $this->get('photosDownloaded') + $this->get('photosStaffDownloaded'),
                $this->get('photosRemoved'),
                $this->get('photosFailed')
            );

            if ($this->get('photosPublicationsPending') > 0) {
                $lines[] = sprintf(
                    'Публікацій, де фотографії дозавантажаться наступним прогоном: %d',
                    $this->get('photosPublicationsPending')
                );
            }
        }

        if ($this->hasErrors()) {
            $lines[] = 'Помилок на окремих обʼєктах: ' . count($this->errors);
        }

        $lines[] = 'Час виконання: ' . $this->duration() . ' с';

        return $lines;
    }
}

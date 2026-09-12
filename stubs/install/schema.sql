-- ---------------------------------------------------------------------------
--  Plusest Site — схема бази даних
--
--  Файл застосовується командою:
--      php vendor/bin/plusestSite migrate --config=config.php
--
--  Правила, яких треба дотримуватись, якщо правите цей файл:
--    * назви таблиць пишемо у фігурних дужках — {objects}, {staff}, {syncState};
--      вони автоматично замінюються на назви з префіксом із config.php;
--    * інструкції розділяються символом ";" у кінці рядка;
--    * рядки, що починаються з "--", вважаються коментарями і не виконуються;
--    * назви стовпців — camelCase, точно як поля у фіді CRM: так у коді
--      синхронізації не потрібні жодні таблиці відповідності імен.
--
--  Усі CREATE TABLE написані з IF NOT EXISTS, тому команду migrate можна
--  запускати повторно — наявні таблиці вона не зачепить.
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
--  {objects} — публікації обʼєктів нерухомості
--
--  ВАЖЛИВО: один рядок — це одна ПУБЛІКАЦІЯ, а не обʼєкт.
--  У CRM для одного обʼєкта можна створити кілька публікацій (від різних
--  співробітників або в різний час), тому первинний ключ — objectPublicationId,
--  а objectId у таблиці може повторюватись.
--
--  Стовпці поділені на три групи:
--    1. Параметри для фільтрації та сортування — окремі стовпці з індексами.
--    2. Стовпець data — повний JSON обʼєкта з фіда. Саме з нього шаблони
--       беруть усі дані для рендерингу (характеристики, фото, тексти, адресу).
--    3. Службові стовпці — хеші для пропуску незмінених обʼєктів, статус, час.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {objects}
(
    -- --- Ідентифікатори ----------------------------------------------------
    `objectPublicationId` VARCHAR(24)      NOT NULL COMMENT 'ID публікації в CRM. Первинний ключ',
    `objectId`            VARCHAR(24)      NOT NULL COMMENT 'ID самого обʼєкта. Може повторюватись у кількох публікаціях',
    `agentId`             VARCHAR(24)               DEFAULT NULL COMMENT 'ID співробітника, автора публікації. Звʼязок із таблицею staff',

    -- --- Класифікація ------------------------------------------------------
    `operation`           VARCHAR(16)      NOT NULL COMMENT 'Операція: sale (продаж) або rent (оренда)',
    `type`                VARCHAR(16)      NOT NULL COMMENT 'Тип нерухомості: apartment, house, land, parking, commerce',
    `whole`               TINYINT(1)       NOT NULL DEFAULT 1 COMMENT 'Обʼєкт цілком (1) або лише його частина (0)',
    `commerceType`        VARCHAR(32)               DEFAULT NULL COMMENT 'Тип комерції — заповнюється лише для type = commerce',
    `landType`            VARCHAR(32)               DEFAULT NULL COMMENT 'Призначення ділянки — лише для type = land',
    `parkingType`         VARCHAR(32)               DEFAULT NULL COMMENT 'Тип автомісця — лише для type = parking',

    -- --- Основні параметри для фільтрів ------------------------------------
    `rooms`               SMALLINT UNSIGNED         DEFAULT NULL COMMENT 'Кількість кімнат',
    `roomsOverall`        SMALLINT UNSIGNED         DEFAULT NULL COMMENT 'Кількість кімнат у всьому обʼєкті (для частини обʼєкта)',
    `areaOverall`         DECIMAL(12, 2)            DEFAULT NULL COMMENT 'Площа всього обʼєкта, кв. м',
    `areaTotal`           DECIMAL(12, 2)            DEFAULT NULL COMMENT 'Площа загальна, кв. м',
    `areaLiving`          DECIMAL(12, 2)            DEFAULT NULL COMMENT 'Площа житлова, кв. м',
    `areaKitchen`         DECIMAL(12, 2)            DEFAULT NULL COMMENT 'Площа кухні, кв. м',
    `areaLand`            DECIMAL(12, 2)            DEFAULT NULL COMMENT 'Площа ділянки, сотки',
    `floor`               SMALLINT                  DEFAULT NULL COMMENT 'Поверх, на якому розташований обʼєкт',
    `floors`              SMALLINT                  DEFAULT NULL COMMENT 'Кількість поверхів у будинку',
    `newBuilding`         TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Новобудова',
    `commissioning`       TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Зданий в експлуатацію',

    -- --- Ціни --------------------------------------------------------------
    --  CRM віддає ціну одразу в трьох валютах (priceGeneralExchange), тому
    --  зберігаємо всі три: відвідувач сайту може перемикати валюту без
    --  повторного перерахунку. Стовпці unit* — ціна за одиницю площі.
    `priceUsd`            DECIMAL(14, 2)            DEFAULT NULL COMMENT 'Загальна ціна в доларах',
    `priceUah`            DECIMAL(14, 2)            DEFAULT NULL COMMENT 'Загальна ціна в гривнях',
    `priceEur`            DECIMAL(14, 2)            DEFAULT NULL COMMENT 'Загальна ціна в євро',
    `unitUsd`             DECIMAL(14, 2)            DEFAULT NULL COMMENT 'Ціна за одиницю площі в доларах',
    `unitUah`             DECIMAL(14, 2)            DEFAULT NULL COMMENT 'Ціна за одиницю площі в гривнях',
    `unitEur`             DECIMAL(14, 2)            DEFAULT NULL COMMENT 'Ціна за одиницю площі в євро',

    -- --- Локація -----------------------------------------------------------
    --  Фільтруємо лише за населеним пунктом, районом міста, ЖК та метро.
    --  Назви дублюються двома мовами, щоб побудувати списки для фільтра
    --  одним запитом, без розбору JSON.
    `cityId`              VARCHAR(32)               DEFAULT NULL COMMENT 'ID населеного пункту',
    `cityUk`              VARCHAR(128)              DEFAULT NULL COMMENT 'Назва населеного пункту українською',
    `cityRu`              VARCHAR(128)              DEFAULT NULL COMMENT 'Назва населеного пункту російською',
    `districtId`          VARCHAR(32)               DEFAULT NULL COMMENT 'ID адміністративного району міста',
    `districtUk`          VARCHAR(128)              DEFAULT NULL COMMENT 'Назва району українською',
    `districtRu`          VARCHAR(128)              DEFAULT NULL COMMENT 'Назва району російською',
    `complexId`           VARCHAR(32)               DEFAULT NULL COMMENT 'ID житлового комплексу',
    `complexUk`           VARCHAR(128)              DEFAULT NULL COMMENT 'Назва ЖК українською',
    `complexRu`           VARCHAR(128)              DEFAULT NULL COMMENT 'Назва ЖК російською',
    `metro`               TEXT                      DEFAULT NULL COMMENT 'JSON зі списком станцій метро та відстанню до них у метрах',
    `metroDistanceMin`    INT UNSIGNED              DEFAULT NULL COMMENT 'Відстань до найближчої станції метро, метри. Для фільтра «біля метро»',
    `lat`                 DECIMAL(10, 8)            DEFAULT NULL COMMENT 'Широта',
    `lon`                 DECIMAL(11, 8)            DEFAULT NULL COMMENT 'Довгота',

    -- --- Медіа -------------------------------------------------------------
    `photosCount`         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Скільки фотографій успішно завантажено локально',
    `multimediaCount`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Скільки відео та посилань на соцмережі має обʼєкт',

    -- --- Повні дані для рендерингу -----------------------------------------
    `data`                LONGTEXT                  DEFAULT NULL COMMENT 'Повний JSON обʼєкта з фіда. Джерело даних для шаблонів',

    -- --- Хеші для пропуску незмінених обʼєктів ------------------------------
    --  hashData — md5 усіх даних публікації, разом зі списком фотографій.
    --  Збігся з тим, що у фіді, — рядок не перезаписуємо, лише оновлюємо
    --  seenAt. Це головна економія часу на великих базах.
    --
    --  hashPhotos — md5 списку фотографій із фіда: те, що ПОВИННО бути
    --  на диску. Пише синхронізація даних.
    --
    --  hashPhotosDisk — той самий md5, але записаний лише ПІСЛЯ того, як
    --  файли справді лягли на диск. Пише синхронізація фотографій.
    --
    --  Отже, «є що завантажити» — це рядки, де hashPhotosDisk відрізняється
    --  від hashPhotos. Один запит, без розбору JSON. А збій завантаження не
    --  змусить нас вважати, що фотографії вже є.
    `hashData`            CHAR(32)                  DEFAULT NULL COMMENT 'md5 усіх даних публікації, разом із фотографіями',
    `hashPhotos`          CHAR(32)                  DEFAULT NULL COMMENT 'md5 списку фотографій із фіда — що повинно бути на диску',
    `hashPhotosDisk`      CHAR(32)                  DEFAULT NULL COMMENT 'md5 списку фотографій, які реально завантажені на диск',

    -- --- Службові ----------------------------------------------------------
    `status`              VARCHAR(16)      NOT NULL DEFAULT 'active' COMMENT 'active — показуємо, sold — реалізований, hidden — прихований з пошуку',
    `seenAt`              DATETIME                  DEFAULT NULL COMMENT 'Коли публікацію останній раз бачили у фіді',
    `createdAt`           DATETIME                  DEFAULT NULL COMMENT 'Коли публікація зʼявилася в локальній базі',
    `updatedAt`           DATETIME                  DEFAULT NULL COMMENT 'Коли рядок останній раз змінювався',

    PRIMARY KEY (`objectPublicationId`),

    -- Основний індекс для сторінки пошуку: спочатку відсікаємо приховані
    -- та реалізовані, потім операцію, тип і місто.
    KEY `idxSearch` (`status`, `operation`, `type`, `cityId`),

    KEY `idxObjectId` (`objectId`),
    KEY `idxAgentId` (`agentId`),
    KEY `idxDistrictId` (`districtId`),
    KEY `idxComplexId` (`complexId`),
    KEY `idxRooms` (`rooms`),
    KEY `idxAreaTotal` (`areaTotal`),
    KEY `idxPriceUsd` (`priceUsd`),
    KEY `idxPriceUah` (`priceUah`),
    KEY `idxPriceEur` (`priceEur`),
    KEY `idxMetroDistanceMin` (`metroDistanceMin`),
    KEY `idxCreatedAt` (`createdAt`),
    KEY `idxSeenAt` (`seenAt`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci COMMENT ='Публікації обʼєктів нерухомості з CRM Plusest';


-- ---------------------------------------------------------------------------
--  {objectMetro} — станції метро публікацій
--
--  Навіщо окрема таблиця, якщо станції вже лежать у стовпці objects.metro
--  ---------------------------------------------------------------------
--  Стовпець metro зберігає станції як JSON — з нього шаблон показує список
--  «поруч: Святошин, 700 м». Для показу цього достатньо, а от для фільтра —
--  ні: відвідувач вибирає до пʼяти станцій і задає відстань, і такий запит
--  по JSON можна зробити лише через LIKE, тобто повним перебором таблиці.
--
--  Тому та сама інформація дублюється тут рядок-на-станцію. Фільтр «поруч
--  зі станціями X, Y, Z у межах 800 м» стає звичайним JOIN по індексу.
--
--  Таблицю заповнює синхронізація: щоразу, коли перезаписується рядок
--  публікації, її станції переписуються повністю. Публікацій без метро тут
--  немає взагалі — у більшості міст України метро не існує.
--
--  Стовпець cityId дублюється з таблиці objects навмисно: список станцій для
--  фільтра будується для вибраного населеного пункту, і без цього стовпця
--  довелось би щоразу приєднувати objects лише заради нього.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {objectMetro}
(
    `objectPublicationId` VARCHAR(24)  NOT NULL COMMENT 'ID публікації. Звʼязок із таблицею objects',
    `metroId`             VARCHAR(32)  NOT NULL COMMENT 'ID станції метро в CRM',
    `nameUk`              VARCHAR(128) DEFAULT NULL COMMENT 'Назва станції українською',
    `nameRu`              VARCHAR(128) DEFAULT NULL COMMENT 'Назва станції російською',
    `distance`            INT UNSIGNED DEFAULT NULL COMMENT 'Відстань від обʼєкта до станції, метри',
    `cityId`              VARCHAR(32)  DEFAULT NULL COMMENT 'ID населеного пункту публікації — для списку станцій у фільтрі',

    -- Одна станція може бути в публікації лише один раз.
    PRIMARY KEY (`objectPublicationId`, `metroId`),

    -- Головний індекс фільтра: вибрані станції плюс обмеження відстані.
    KEY `idxMetroDistance` (`metroId`, `distance`),

    -- Побудова списку станцій для вибраного міста.
    KEY `idxCity` (`cityId`, `metroId`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci COMMENT ='Станції метро публікацій — для фільтра «поруч із метро»';


-- ---------------------------------------------------------------------------
--  {staff} — співробітники агентства
--
--  Приходять окремим масивом staff у тому самому фіді. Звʼязок з обʼєктами —
--  через agentId. Фотографія співробітника завантажується локально так само,
--  як фотографії обʼєктів.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {staff}
(
    `agentId`    VARCHAR(24) NOT NULL COMMENT 'ID співробітника в CRM. Первинний ключ',
    `surname`    VARCHAR(96) DEFAULT NULL COMMENT 'Прізвище',
    `name`       VARCHAR(96) DEFAULT NULL COMMENT 'Імʼя',
    `patronymic` VARCHAR(96) DEFAULT NULL COMMENT 'Батькові',
    `sex`        VARCHAR(8)  DEFAULT NULL COMMENT 'Стать: man або woman',
    `photo`      VARCHAR(64) DEFAULT NULL COMMENT 'ID фотографії у хмарі CRM',
    `data`       LONGTEXT    DEFAULT NULL COMMENT 'Повний JSON співробітника: телефони, посилання на соцмережі',
    `hashData`   CHAR(32)    DEFAULT NULL COMMENT 'md5 даних співробітника — щоб не перезаписувати без потреби',
    `seenAt`     DATETIME    DEFAULT NULL COMMENT 'Коли співробітника останній раз бачили у фіді',
    `createdAt`  DATETIME    DEFAULT NULL COMMENT 'Коли співробітник зʼявився в локальній базі',
    `updatedAt`  DATETIME    DEFAULT NULL COMMENT 'Коли рядок останній раз змінювався',

    PRIMARY KEY (`agentId`),
    KEY `idxSurname` (`surname`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci COMMENT ='Співробітники агентства нерухомості';


-- ---------------------------------------------------------------------------
--  {syncState} — службові значення синхронізації
--
--  Проста таблиця «ключ — значення». Тут скрипт зберігає час останнього
--  успішного прогону, версії завантажених моделей даних, підсумки й помилки.
--  Не намагайтесь тримати тут щось велике: для логів є файл у каталозі data.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS {syncState}
(
    `name`      VARCHAR(64) NOT NULL COMMENT 'Назва значення, напр. lastSyncAt',
    `value`     LONGTEXT    DEFAULT NULL COMMENT 'Значення. Складні структури зберігаємо як JSON',
    `updatedAt` DATETIME    DEFAULT NULL COMMENT 'Коли значення останній раз змінювалось',

    PRIMARY KEY (`name`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci COMMENT ='Службовий стан синхронізації';

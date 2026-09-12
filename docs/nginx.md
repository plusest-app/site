# Налаштування вебсервера

## Головний принцип

Заготовка складається з двох частин, і це поділ не випадковий:

```
plusestSite/
├── config.php          ← НЕ віддавати з вебу
├── data/               ← НЕ віддавати з вебу (моделі, лог, ключ установника)
├── templates/          ← НЕ віддавати з вебу
└── public/             ← ЛИШЕ цей каталог бачить браузер
    ├── index.php       front controller: усі сторінки розділу
    ├── cron.php        запуск синхронізації за URL (за потреби)
    ├── install/        веб-установник
    ├── assets/         site.css і site.js
    └── photos/         завантажені фотографії
```

Вебсерверу показуємо **тільки `public/`**. Усе інше лежить поруч, на рівень вище,
тому дістатися до `config.php` із паролем до бази через браузер фізично неможливо —
жодних правил `deny` для цього не потрібно.

Каталог `public/install/` — це звичайний підкаталог із `index.php`, тому окремих
правил для нього не потрібно: він відкриється за адресою `/base/install/` сам.
Пройти установник без ключа з файлу `data/installKey.txt` неможливо, а файл цей
лежить поза `public/` — з вебу його не видно.

Далі — три варіанти підключення. Виберіть той, що відповідає вашій ситуації.

---

## Варіант A. Підкаталог існуючого сайту

Найчастіший випадок: сайт уже працює на `site.com`, а базу нерухомості потрібно
відкрити за адресою `site.com/base/`.

```nginx
server {
    listen 443 ssl;
    server_name site.com;

    root /var/www/site.com/public_html;

    # ... тут ваш основний сайт ...

    # --- База нерухомості ---------------------------------------------------

    # Запит без завершального слеша перекидаємо на слеш,
    # інакше location нижче не спрацює.
    location = /base {
        return 301 /base/;
    }

    # ^~ означає: якщо шлях починається з /base/, інші regex-локації
    # основного сайту вже не перевіряються.
    location ^~ /base/ {
        alias /var/www/site.com/plusestSite/public/;
        index index.php;

        # У каталозі з фотографіями PHP не виконуємо ніколи.
        # Це правило мусить стояти ПЕРЕД загальним правилом для .php,
        # бо nginx перебирає regex-локації в порядку опису.
        location ~ ^/base/photos/.*\.php$ {
            deny all;
        }

        # Наявний файл віддаємо як є, інакше — у front controller.
        try_files $uri $uri/ @plusestSite;

        location ~ \.php$ {
            include        fastcgi_params;
            fastcgi_pass   unix:/run/php/php7.3-fpm.sock;
            fastcgi_index  index.php;

            # ВАЖЛИВО: разом з alias використовуємо $request_filename,
            # а НЕ звичний $document_root$fastcgi_script_name — інакше
            # PHP отримає неправильний шлях до скрипта і ви побачите
            # "No input file specified".
            fastcgi_param  SCRIPT_FILENAME $request_filename;
        }

        # Фотографії кешуємо надовго: файл із таким імʼям ніколи не змінюється,
        # при зміні фото в CRM зʼявляється новий ідентифікатор.
        location ~ ^/base/photos/ {
            expires 30d;
            add_header Cache-Control "public, immutable";
            access_log off;
        }
    }

    location @plusestSite {
        rewrite ^/base/(.*)$ /base/index.php?plusestPath=$1 last;
    }
}
```

У `config.php` при цьому:

```php
'urls' => [
    'base'   => '/base/',
    'photos' => '/base/photos/',
],
```

---

## Варіант B. Окремий домен або піддомен

Простіший і надійніший варіант: `realty.site.com` або окремий домен.
Тут працює звичайний `root`, без жодних граблів з `alias`.

```nginx
server {
    listen 443 ssl;
    server_name realty.site.com;

    root /var/www/site.com/plusestSite/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?plusestPath=$uri;
    }

    location ~ ^/photos/.*\.php$ {
        deny all;
    }

    location ~ \.php$ {
        include        fastcgi_params;
        fastcgi_pass   unix:/run/php/php7.3-fpm.sock;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ ^/photos/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
}
```

У `config.php`:

```php
'urls' => [
    'base'   => '/',
    'photos' => '/photos/',
],
```

---

## Варіант C. Підкаталог через символьне посилання

Якщо варіант A не запрацював (директива `alias` разом із `try_files` у старих
версіях nginx поводиться примхливо) — обійдіть `alias` символьним посиланням:

```bash
ln -s /var/www/site.com/plusestSite/public /var/www/site.com/public_html/base
```

Тепер каталог доступний під звичайним `root` основного сайту:

```nginx
location ^~ /base/ {
    try_files $uri $uri/ /base/index.php?plusestPath=$uri;
}
```

Переконайтесь, що в nginx увімкнено `disable_symlinks off;` (значення за
замовчуванням) — інакше він відмовиться йти за посиланням.

---

## Важливо!!! Захист каталогу

Якщо ви розмістили всі скрипти в публічній частині web-сервера, обовʼязково закрийте цей каталог від прямого запуску.
Наприклад, якщо ви розпакували архів зі скриптами в кореневий каталог сайту `/var/www/site.com/public_html/plusestSite/` то будь-хто може звернутися до файлу `http://site.com/plusestSite/config.php` або до іншого файлу в каталозі `plusestSite`. А якщо в nginx не включений обробник php, файл `config.php` взагалі можна буде завантажити й подивитися логін і пароль до бази даних.

По запиту `/plusestSite` потрібно віддавати будь-яку помилку, наприклад 404.


```nginx
location ^~ /plusestSite {
    return 404;
}

location ^~ /base/ {
    # продовження конфігу
```

Рекомендуємо не завантажувати каталог `plusestSite` у публічну частину `/public_html` web-сервера.

---

## Apache

Якщо у вас Apache, замість правил nginx використовуйте `Alias` у конфігурації
віртуального хоста:

```apache
Alias /base /var/www/site.com/plusestSite/public

<Directory /var/www/site.com/plusestSite/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

І файл `public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /base/

    # Наявні файли й каталоги віддаємо як є
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Решту — у front controller
    RewriteRule ^(.*)$ index.php?plusestPath=$1 [QSA,L]
</IfModule>

# У каталозі з фотографіями нічого не виконуємо
<Directory "photos">
    php_flag engine off
    RemoveHandler .php .phtml
</Directory>
```

---

## Типові помилки

| Симптом | Причина |
|---|---|
| `No input file specified` | Разом з `alias` вказано `SCRIPT_FILENAME $document_root$fastcgi_script_name`. Потрібно `$request_filename` |
| Головна відкривається, внутрішні сторінки дають 404 | Не спрацював `try_files` / `@plusestSite`. Перевірте, що назва named location у `try_files` і в описі `location @…` збігається |
| `/base` дає 404, а `/base/` працює | Немає редиректу `location = /base { return 301 /base/; }` |
| Замість сторінки віддається код PHP | Не описано `location ~ \.php$` усередині блоку з `alias` |
| Фотографії не показуються, у логах 404 | Значення `urls.photos` у `config.php` не збігається зі шляхом, який віддає nginx |
| 403 на всі запити | php-fpm працює під іншим користувачем і не має доступу до каталогу. Перевірте власника `plusestSite/` |

## Перевірка після налаштування

```bash
# Front controller відповідає
curl -I https://site.com/base/

# Службові файли недоступні (очікуємо 404, а не вміст файлу)
curl -I https://site.com/base/../config.php
curl -I https://site.com/base/../data/sync.log

# php-fpm бачить правильний шлях до скрипта
curl -s https://site.com/base/ | head -5
```

Останній рядок не має містити тексту `No input file specified` чи
непрочитаного PHP-коду.

## Права на каталоги

Скрипт синхронізації запускається з cron під вашим користувачем, а сторінки
віддає php-fpm під своїм (часто `www-data`). Обидва мусять мати доступ на запис
до `data/` і `public/photos/`. Найпростіше — зробити їх власником php-fpm:

```bash
chown -R www-data:www-data /var/www/site.com/plusestSite/data
chown -R www-data:www-data /var/www/site.com/plusestSite/public/photos
```

І запускати cron від того самого користувача:

```cron
17,47 * * * * www-data /usr/bin/php /var/www/site.com/plusestSite/bin/sync.php
```

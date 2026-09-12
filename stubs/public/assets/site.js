/* ===========================================================================
 *  Скрипти розділу нерухомості
 * ===========================================================================
 *
 *  Що тут відбувається
 *  -------------------
 *   1. Обране — список ID у localStorage браузера, сердечко на картці й
 *      окремий розділ, який довантажує обʼєкти з сервера.
 *   2. Фільтр — блок «Більше параметрів» і логіка залежностей: кімнати не
 *      показуються для ділянки, тип комерції — лише для комерції тощо.
 *   3. Випадні списки Select2 у фільтрі.
 *   4. Карусель фотографій у картці (Owl Carousel).
 *   5. Галерея на весь екран (Fancybox), окрема для кожної публікації.
 *   6. Розкладка характеристик у стовпці різної висоти (Masonry).
 *   7. Паралакс фонової фотографії в заголовку сторінки публікації.
 *   8. Память про адресу пошуку: кнопка «До списку обʼєктів» повертає
 *      відвідувача до того ж фільтра, з якого він прийшов.
 *   9. Власний випадний список сортування.
 *
 *  Залежності
 *  ----------
 *  Select2 і Owl Carousel написані як плагіни jQuery, тому jQuery підключений
 *  у layout.php перед цим файлом. Fancybox і Masonry — самостійні бібліотеки.
 *  Усе інше тут — звичайний JavaScript без залежностей.
 *
 *  Якщо якоїсь бібліотеки на сторінці немає (ви прибрали її з layout.php),
 *  відповідна частина просто не виконується: перед кожним викликом стоїть
 *  перевірка. Розділ залишається робочим — карусель стане однією
 *  фотографією, галерея — звичайним посиланням на файл, списки — звичайними
 *  select.
 *
 *  Тексти для динамічних частин приходять із PHP у window.plusestSite —
 *  див. кінець layout.php. Правити їх тут не потрібно: вони перекладаються
 *  через templates/lang.php, як і решта розділу.
 * =========================================================================== */

(function () {
    'use strict';

    /* =======================================================================
     *  Налаштування й дрібні помічники
     * ======================================================================= */

    /**
     * Дані, передані з PHP: адреси розділу та тексти інтерфейсу.
     */
    var site = window.plusestSite || {};
    var text = site.text || {};

    /**
     * Ключ, під яким у localStorage лежить список обраного.
     *
     * Це саме localStorage, а не кука: список не потрібен серверу при кожному
     * запиті, а кука на кілька десятків ID подорожчала б кожен запит до
     * сайту. Термін життя localStorage не обмежений — відвідувач знайде своє
     * обране і через місяць.
     */
    var FAVORITES_KEY = 'plusestFavorites';

    /**
     * Ключі, під якими в sessionStorage лежить память про сторінку, з якої
     * відвідувач перейшов до публікації.
     *
     * sessionStorage, а не localStorage: це память про поточний сеанс
     * перегляду, і наступного разу вона тільки збивала б з пантелику.
     */
    var RETURN_URL_KEY = 'plusestReturnUrl';
    var RETURN_KIND_KEY = 'plusestReturnKind';

    /**
     * Скільки обʼєктів запитувати в розділі «Обране» за один раз.
     *
     * Збігається з обмеженням на боці сервера: більше він однаково не
     * віддасть.
     */
    var FAVORITES_LIMIT = 100;

    /**
     * Коротший запис для document.querySelectorAll у вигляді масиву.
     *
     * @param {string} selector CSS-селектор
     * @param {Element|Document} [scope] Де шукати; за замовчуванням — уся сторінка
     *
     * @returns {Element[]}
     */
    function all(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    /**
     * Чи є на сторінці jQuery.
     *
     * @returns {boolean}
     */
    function hasJquery() {
        return typeof window.jQuery === 'function';
    }


    /* =======================================================================
     *  1. Обране
     * ======================================================================= */

    /**
     * Читає список обраного з localStorage.
     *
     * Будь-яка помилка (браузер у приватному режимі, зіпсоване значення)
     * означає «обраного немає»: розділ мусить працювати й без сховища.
     *
     * @returns {string[]} Ідентифікатори публікацій
     */
    function favoritesRead() {
        try {
            var raw = window.localStorage.getItem(FAVORITES_KEY);
            var list = raw ? JSON.parse(raw) : [];

            if (!Array.isArray(list)) {
                return [];
            }

            // Формат ID перевіряємо і тут: у сховище могло потрапити щось
            // чуже, а ці значення підуть у запит до сервера.
            return list.filter(function (id) {
                return typeof id === 'string' && /^[A-Za-z0-9]{1,24}$/.test(id);
            });
        } catch (e) {
            return [];
        }
    }

    /**
     * Записує список обраного.
     *
     * @param {string[]} list Ідентифікатори публікацій
     *
     * @returns {void}
     */
    function favoritesWrite(list) {
        try {
            window.localStorage.setItem(FAVORITES_KEY, JSON.stringify(list));
        } catch (e) {
            // Сховище недоступне або переповнене — тоді обране просто не
            // зберігається. Показувати відвідувачу помилку тут нема за що.
        }
    }

    /**
     * Додає обʼєкт в обране або прибирає з нього.
     *
     * @param {string} id Ідентифікатор публікації
     *
     * @returns {boolean} Чи обʼєкт тепер в обраному
     */
    function favoritesToggle(id) {
        var list = favoritesRead();
        var at = list.indexOf(id);

        if (at === -1) {
            // Нові додаємо на початок: у розділі «Обране» щойно відкладений
            // обʼєкт мусить бути першим.
            list.unshift(id);
        } else {
            list.splice(at, 1);
        }

        favoritesWrite(list);
        favoritesRefresh();

        return at === -1;
    }

    /**
     * Приводить вигляд усіх сердечок і лічильника до вмісту сховища.
     *
     * Викликається після кожної зміни, а також після появи нових карточок на
     * сторінці — наприклад у розділі «Обране».
     *
     * @returns {void}
     */
    function favoritesRefresh() {
        var list = favoritesRead();

        all('[data-pl-favorite]').forEach(function (button) {
            var active = list.indexOf(button.getAttribute('data-pl-favorite')) !== -1;

            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');

            var label = active ? text.favoriteRemove : text.favoriteAdd;

            if (label) {
                button.setAttribute('title', label);
                button.setAttribute('aria-label', label);
            }
        });

        all('[data-pl-fav-count]').forEach(function (counter) {
            counter.textContent = String(list.length);

            // Нуль не показуємо: порожній лічильник біля сердечка виглядає
            // як помилка, а не як «ви ще нічого не відкладали».
            if (list.length === 0) {
                counter.setAttribute('hidden', 'hidden');
            } else {
                counter.removeAttribute('hidden');
            }
        });
    }

    /**
     * Ставить обробник на всі сердечка одразу.
     *
     * Обробник один і висить на документі, тому працює і для карточок, які
     * зʼявилися пізніше — довантажених у розділі «Обране».
     *
     * @returns {void}
     */
    function initFavoriteButtons() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('[data-pl-favorite]') : null;

            if (!button) {
                return;
            }

            event.preventDefault();

            var id = button.getAttribute('data-pl-favorite');
            var added = favoritesToggle(id);

            // На сторінці обраного прибране сердечко мусить прибрати й саму
            // картку: інакше відвідувач бачить у списку те, чого там уже нема.
            var list = document.querySelector('[data-pl-fav-list]');

            if (!added && list) {
                var card = button.closest('[data-pl-card]');

                if (card) {
                    card.style.transition = 'opacity .2s ease';
                    card.style.opacity = '0';

                    window.setTimeout(function () {
                        card.parentNode.removeChild(card);
                        favoritesRenderEmpty();
                    }, 200);
                }
            }
        });

        favoritesRefresh();
    }

    /**
     * Показує «в обраному нічого немає», якщо на сторінці не залишилось карточок.
     *
     * @returns {void}
     */
    function favoritesRenderEmpty() {
        var list = document.querySelector('[data-pl-fav-list]');
        var empty = document.querySelector('[data-pl-fav-empty]');

        if (!list || !empty) {
            return;
        }

        var has = list.querySelector('[data-pl-card]') !== null;

        list.hidden = !has;
        empty.hidden = has;
    }

    /**
     * Довантажує обʼєкти обраного з сервера.
     *
     * Сторінка '/base/favorites/' приходить порожньою: сервер не знає, що
     * відклав саме цей відвідувач. Список лежить у браузері, тому саме
     * браузер і надсилає його — а сервер віддає готову верстку карточок.
     *
     * Верстку рендерить той самий шаблон, що й у списку обʼєктів, тому
     * картка, яку ви переробили під свій дизайн, тут виглядає так само.
     *
     * @returns {void}
     */
    function initFavoritesPage() {
        var list = document.querySelector('[data-pl-fav-list]');

        if (!list) {
            return;
        }

        var loading = document.querySelector('[data-pl-fav-loading]');
        var ids = favoritesRead().slice(0, FAVORITES_LIMIT);

        /**
         * Прибирає рядок «завантажуємо».
         *
         * @param {boolean} checkEmpty Чи вирішувати після цього, показувати
         *                             блок «в обраному нічого немає». При
         *                             помилці — ні: інакше він накрив би саме
         *                             повідомлення про помилку.
         */
        function done(checkEmpty) {
            if (loading) {
                loading.hidden = true;
            }

            if (checkEmpty !== false) {
                favoritesRenderEmpty();
            }
        }

        if (ids.length === 0) {
            done();

            return;
        }

        // Дуже старий браузер без fetch. Список у сховищі є, але завантажити
        // обʼєкти ми не можемо — показуємо це прямо, а не порожню сторінку.
        if (typeof window.fetch !== 'function') {
            list.innerHTML = '<div class="pl-note pl-error">' + (text.favoritesFailed || '') + '</div>';
            list.hidden = false;

            if (loading) {
                loading.hidden = true;
            }

            return;
        }

        var url = list.getAttribute('data-pl-fav-list');

        window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'ids=' + ids.join(','), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                list.innerHTML = html;

                // Обʼєкти, яких сервер не знайшов, прибираємо зі сховища:
                // інакше список обраного ріс би вічно й щоразу тягнув за
                // собою запити про давно видалені публікації.
                favoritesPrune(list, ids);

                // Карточки щойно зʼявилися — вмикаємо для них карусель,
                // галерею й сердечка.
                initCards(list);
                favoritesRefresh();
                done();
            })
            .catch(function () {
                list.innerHTML = '<div class="pl-note pl-error">'
                    + (text.favoritesFailed || '') + '</div>';
                list.hidden = false;

                done(false);
            });
    }

    /**
     * Прибирає зі сховища ID, яких сервер не знайшов.
     *
     * @param {Element} list      Контейнер із відповіддю сервера
     * @param {string[]} requested Що ми запитували
     *
     * @returns {void}
     */
    function favoritesPrune(list, requested) {
        var marker = list.querySelector('[data-pl-found]');

        if (!marker) {
            return;
        }

        var found = (marker.getAttribute('data-pl-found') || '').split(',').filter(Boolean);

        // Прибираємо лише те, що ми справді запитували: решта списку могла
        // просто не влізти в обмеження FAVORITES_LIMIT.
        var kept = favoritesRead().filter(function (id) {
            return requested.indexOf(id) === -1 || found.indexOf(id) !== -1;
        });

        favoritesWrite(kept);

        if (found.length !== requested.length) {
            var note = document.querySelector('[data-pl-fav-gone]');

            if (note) {
                note.hidden = false;
            }
        }
    }

    /**
     * Кнопка «Очистити список» у розділі обраного.
     *
     * @returns {void}
     */
    function initFavoritesClear() {
        all('[data-pl-fav-clear]').forEach(function (button) {
            button.addEventListener('click', function () {
                favoritesWrite([]);
                favoritesRefresh();

                var list = document.querySelector('[data-pl-fav-list]');

                if (list) {
                    list.innerHTML = '';
                    favoritesRenderEmpty();
                }
            });
        });
    }


    /* =======================================================================
     *  2. Фільтр
     * ======================================================================= */

    /**
     * Блок «Більше параметрів».
     *
     * Блок позиційований абсолютно, тому не розсовує сторінку — він
     * накриває список обʼєктів. Закривається кліком поза собою й клавішею
     * Escape: обидва способи відвідувач пробує однаково часто.
     *
     * @returns {void}
     */
    function initFilterPanel() {
        var button = document.querySelector('[data-pl-more]');
        var panel = document.querySelector('[data-pl-extra]');

        if (!button || !panel) {
            return;
        }

        function open() {
            panel.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
        }

        function close() {
            panel.classList.remove('is-open');
            button.setAttribute('aria-expanded', 'false');
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();

            if (panel.classList.contains('is-open')) {
                close();
            } else {
                open();
            }
        });

        document.addEventListener('click', function (event) {
            if (!panel.classList.contains('is-open')) {
                return;
            }

            // Клік по самому блоку, по кнопці, що його відкрила, або по
            // випадному меню Select2 — це не «клік поза блоком»: меню
            // Select2 живе в кінці body, а не всередині панелі.
            if (panel.contains(event.target)
                || button.contains(event.target)
                || (event.target.closest && (event.target.closest('.select2-container') || event.target.closest('.select2-selection__choice__remove')))) {
                return;
            }

            close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
    }

    /**
     * Логічні залежності між полями фільтра.
     *
     * Показувати «кількість кімнат» для земельної ділянки безглуздо, а «тип
     * комерції» — для квартири. Тому кожне поле, яке залежить від типу
     * нерухомості, помічене в шаблоні атрибутами:
     *
     *     data-pl-types="apartment house commerce"   для яких типів показуємо
     *     data-pl-strict                             показувати ТІЛЬКИ для них
     *
     * Без `data-pl-strict` поле показується ще й тоді, коли тип не вибраний
     * зовсім: відвідувач, який шукає «щось до 50 000», мусить бачити і
     * кімнати, і поверх. А з `data-pl-strict` — ні: «тип комерції» без
     * вибраної комерції не значить нічого.
     *
     * Приховані поля ще й вимикаються (disabled). Це важливо: інакше
     * браузер надіслав би значення, яке відвідувач більше не бачить, і у
     * вибірку потрапила б умова, про яку він не знає.
     *
     * @returns {void}
     */
    function initFilterDependencies() {
        var form = document.querySelector('[data-pl-filter]');

        if (!form) {
            return;
        }

        var typeInput = form.querySelector('[data-pl-type]');
        var newBuildingInput = form.querySelector('#plNewBuilding');
        var fields = all('[data-pl-types]', form);

        if (!typeInput || fields.length === 0) {
            return;
        }

        function apply() {
            var type = typeInput.value;
            var newBuilding = newBuildingInput.value;

            fields.forEach(function (field) {
                var types = (field.getAttribute('data-pl-types') || '').split(/\s+/);
                var strict = field.hasAttribute('data-pl-strict');

                var visible = type === ''
                    ? !strict
                    : types.indexOf(type) !== -1;

                if (field.hasAttribute('data-pl-commissioning') && newBuilding !== 'yes') {

                    visible = false;

                }

                field.classList.toggle('is-hidden', !visible);

                all('input, select', field).forEach(function (input) {
                    input.disabled = !visible;
                });
            });
        }

        typeInput.addEventListener('change', apply);
        newBuildingInput.addEventListener('change', apply);
        apply();
    }

    /**
     * Випадні списки Select2.
     *
     * Звичайний select із сотнею населених пунктів непридатний до
     * використання: у ньому немає пошуку, а множинний вибір на дотиковому
     * екрані взагалі неможливий. Select2 вирішує і те, і те.
     *
     * Без jQuery (ви прибрали його з layout.php) поля залишаються звичайними
     * select — фільтр так само працює, просто виглядає простіше.
     *
     * @returns {void}
     */
    function initSelects() {
        if (!hasJquery() || typeof window.jQuery.fn.select2 !== 'function') {
            return;
        }

        var $ = window.jQuery;

        $('[data-pl-select2]').each(function () {
            var $select = $(this);

            var options = {
                width: '100%',

                // Поле пошуку показуємо лише у довгих списках: у переліку з
                // трьох операцій воно тільки заважає.
                minimumResultsForSearch: parseInt($select.attr('data-pl-search') || '8', 10),

                // Меню кріпимо до батьківського блоку, а не до body: інакше
                // у блоці «Більше параметрів» воно опинилось би під ним.
                dropdownParent: $select.closest('[data-pl-extra]').length
                    ? $select.closest('[data-pl-extra]')
                    : $(document.body),

                language: {
                    noResults: function () {
                        return text.noResults || '—';
                    },
                    searching: function () {
                        return text.searching || '...';
                    },
                    inputTooShort: function () {
                        return text.searching || '...';
                    }
                }
            };

            if ($select.prop('multiple')) {
                options.closeOnSelect = false;
            }

            $select.select2(options);

            // Select2 підміняє select власною розміткою, тому подію change
            // на початковому елементі треба переслати вручну — інакше
            // логіка залежностей не дізнається про зміну типу нерухомості.
            $select.on('select2:select select2:unselect', function () {
                this.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    /**
     * Поля «від — до» приймають лише числа.
     *
     * Сервер сміття однаково відкине, але краще не дати його ввести: інакше
     * відвідувач натисне «Показати» й отримає результат без своєї умови,
     * не зрозумівши, чому.
     *
     * @returns {void}
     */
    function initNumericInputs() {
        all('[data-pl-numeric]').forEach(function (input) {
            input.addEventListener('input', function () {
                // Кому як десятковий розділювач залишаємо: саме її набирають
                // з української розкладки, а замінить її на точку сервер.
                var cleaned = input.value.replace(/[^0-9.,\s]/g, '');

                if (cleaned !== input.value) {
                    input.value = cleaned;
                }
            });
        });
    }


    /* =======================================================================
     *  3. Карточки: карусель, галерея
     * ======================================================================= */

    /**
     * Карусель фотографій у картці обʼєкта.
     *
     * Висота блоку не змінюється при прокручуванні: пропорцію 4:3 задає CSS
     * кожному слайду окремо, тому карусель не має чого перераховувати.
     *
     * @param {Element|Document} [scope] Де шукати карточки
     *
     * @returns {void}
     */
    function initCarousels(scope) {
        if (!hasJquery() || typeof window.jQuery.fn.owlCarousel !== 'function') {
            return;
        }

        var $ = window.jQuery;

        $('[data-pl-carousel]', scope || document).each(function () {
            var $carousel = $(this);

            // Повторна ініціалізація зламала б уже готову карусель.
            if ($carousel.hasClass('owl-loaded')) {
                return;
            }

            // Одна фотографія — каруселі нема сенсу: ні стрілок, ні точок.
            if ($carousel.children().length < 2) {
                return;
            }

            $carousel.owlCarousel({
                items: 1,
                nav: true,
                dots: true,
                mouseDrag: true,
                touchDrag: true,
                autoHeight: false,

                // Безкінечне прокручування (loop) увімкнути не можна: воно
                // працює через клони слайдів, а клони — це ті самі посилання
                // з data-fancybox. Галерея зібрала б кожну фотографію по два
                // рази. Тому rewind: із останнього фото стрілка «далі»
                // повертає на перше, і жодних клонів не створюється.
                loop: false,
                rewind: true,

                // Ліниве завантаження робить сам браузер — атрибутом
                // loading="lazy" у шаблоні картки. Вбудований у карусель
                // механізм вимагав би замінити src на data-src, і без
                // JavaScript фотографій не було б узагалі.
                lazyLoad: false,

                navText: [
                    '<i class="fi fi-ts-angle-small-left" aria-hidden="true"></i>'
                        + '<span class="pl-sr">' + (text.prevPhoto || '') + '</span>',
                    '<i class="fi fi-ts-angle-small-right" aria-hidden="true"></i>'
                        + '<span class="pl-sr">' + (text.nextPhoto || '') + '</span>'
                ]
            });
        });
    }

    /**
     * Галерея на весь екран.
     *
     * Fancybox групує фотографії за значенням атрибута data-fancybox, а ми
     * ставимо туди ідентифікатор публікації. Тому в галереї, відкритій із
     * картки, відвідувач листає фотографії ЦЬОГО обʼєкта — і не потрапляє
     * випадково до сусіднього.
     *
     * @returns {void}
     */
    function initGallery() {
        if (typeof window.Fancybox === 'undefined') {
            return;
        }

        window.Fancybox.bind('[data-fancybox]', {
            // Підпис під фотографією беремо з атрибута data-caption — там
            // стоїть назва обʼєкта.
            Toolbar: {
                display: {
                    left: ['infobar'],
                    middle: [],
                    right: ['slideshow', 'fullscreen', 'thumbs', 'close']
                }
            }
        });
    }

    /**
     * Готує щойно додані карточки до роботи.
     *
     * @param {Element|Document} scope Контейнер із новими карточками
     *
     * @returns {void}
     */
    function initCards(scope) {
        initCarousels(scope);
    }


    /* =======================================================================
     *  4. Сторінка публікації
     * ======================================================================= */

    /**
     * Розкладка характеристик у стовпці різної висоти.
     *
     * Розділів характеристик буває від двох до десяти, і висота в них дуже
     * різна. Звичайна сітка залишила б під коротким розділом порожнє місце,
     * тому вертикальну укладку рахує Masonry.
     *
     * @returns {void}
     */
    function initMasonry() {
        if (typeof window.Masonry === 'undefined') {
            return;
        }

        all('[data-pl-masonry]').forEach(function (container) {
            new window.Masonry(container, {
                itemSelector: '.pl-masonry-item',
                percentPosition: true,

                // Ширину стовпця беремо з першого елемента, а не задаємо
                // числом: у CSS вона різна для телефона й монітора.
                columnWidth: '.pl-masonry-item'
            });
        });
    }

    /**
     * Паралакс фонової фотографії в заголовку публікації.
     *
     * Фон зсувається вдвічі повільніше за сторінку — заголовок наче
     * «пливе» над фотографією. Ефект декоративний, тому:
     *
     *   * рахуємо його в requestAnimationFrame, щоб не гальмувати
     *     прокручування;
     *   * вимикаємо, якщо відвідувач попросив у системі прибрати анімації;
     *   * вимикаємо на вузьких екранах — там прокручування й без нього не
     *     завжди плавне (це робить CSS).
     *
     * @returns {void}
     */
    function initParallax() {
        var layer = document.querySelector('[data-pl-parallax]');

        if (!layer) {
            return;
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reduced) {
            return;
        }

        var pending = false;

        function update() {
            pending = false;

            var hero = layer.parentNode;
            var box = hero.getBoundingClientRect();

            // Заголовок пішов за межі екрана — рахувати нічого.
            if (box.bottom < 0 || box.top > window.innerHeight) {
                return;
            }

            // Знак мінус: сторінка йде вгору, фон — за нею, але повільніше.
            var shift = box.top * -0.25;

            layer.style.transform = 'scale(1.06) translate3d(0, ' + shift.toFixed(1) + 'px, 0)';
        }

        window.addEventListener('scroll', function () {
            if (!pending) {
                pending = true;
                window.requestAnimationFrame(update);
            }
        }, { passive: true });

        update();
    }

    /**
     * Рендеринг мапи з маркером у центрі.
     * Використовується на сторінці об'єкта для відображення його положення на карті за геокоординатами.
     */
    function initMap() {

        if (typeof window.maplibregl === 'undefined') {
            return;
        }

        all('.pl-map').forEach(function (container) {

            // Ініціалізація мапи та маркера за координатами
            var coordinates = JSON.parse(container.getAttribute('data-coordinate'));

            var map = new maplibregl.Map({
                container: container,
                style: 'https://tiles.openfreemap.org/styles/liberty',
                cooperativeGestures: true,
                center: coordinates,
                bearing: 0,
                zoom: 15.5,
                pitch: 45
            });

            map.addControl(new maplibregl.NavigationControl());

            new maplibregl.Marker({
                color: "#FF0000",
                draggable: false
            }).setLngLat(coordinates).addTo(map);

            // Відключаємо стандартний зум коліщатком миші
            map.scrollZoom.disable();

            // Змінна для відстеження стану клавіші Ctrl
            var isCtrlPressed = false;

            // Слідкуємо за натисканням та відпусканням Ctrl
            window.addEventListener('keydown', (e) => {
                if (e.key === 'Control') {
                    isCtrlPressed = true;
                    map.scrollZoom.enable();
                }
            });

            window.addEventListener('keyup', (e) => {
                if (e.key === 'Control') {
                    isCtrlPressed = false;
                    map.scrollZoom.disable();
                }
            });

            // Прапор, який відповідає за роботу анімації
            var isRotating = true;

            // Функція плавного обертання мапи
            var rotateCamera = function () {
                if (!isRotating) return;

                var currentBearing = map.getBearing();
                map.setBearing(currentBearing + 0.02);

                window.requestAnimationFrame(rotateCamera);
            }

            // Запуск анімації та локалізація після завантаження мапи
            map.on('load', function () {
                rotateCamera(0);

                var targetLanguage = document.documentElement.lang;
                var layers = map.getStyle().layers;

                layers.forEach((layer) => {
                    if (layer.type === 'symbol' && layer.layout && layer.layout['text-field']) {
                        map.setLayoutProperty(layer.id, 'text-field', [
                            'coalesce',
                            ['get', `name:${targetLanguage}`],
                            ['get', 'name']
                        ]);
                    }
                });

            });

            // Зупинка анімації при взаємодії користувача з мапою
            var stopRotation = function () {
                if (isRotating) {
                    isRotating = false;
                }
            }

            map.on('mousedown', stopRotation);
            map.on('touchstart', stopRotation);
            map.on('zoom', stopRotation);
            map.on('wheel', function () {
                if (isCtrlPressed) {
                    stopRotation();
                }
            });

        });

    }


    /* =======================================================================
     *  5. Память про сторінку, з якої прийшов відвідувач
     * ======================================================================= */

    /**
     * Запамʼятовує адресу списку обʼєктів.
     *
     * Відвідувач налаштував фільтр, відкрив обʼєкт — і кнопка «До списку
     * обʼєктів» мусить повернути його саме до того ж фільтра, а не до
     * порожнього списку. Адреса пошуку зберігається в sessionStorage, бо
     * це память про поточний перегляд, а не про відвідувача.
     *
     * Так само запамʼятовуємо і розділ «Обране»: якщо публікацію відкрили
     * звідти, повертатись треба туди ж.
     *
     * @returns {void}
     */
    function rememberOrigin() {
        var kind = document.body.getAttribute('data-pl-page');

        if (kind !== 'list' && kind !== 'favorites') {
            return;
        }

        try {
            window.sessionStorage.setItem(RETURN_KIND_KEY, kind);

            if (kind === 'list') {
                window.sessionStorage.setItem(
                    RETURN_URL_KEY,
                    window.location.pathname + window.location.search
                );
            }
        } catch (e) {
            // Приватний режим браузера — тоді кнопка поверне до звичайного
            // списку обʼєктів. Не критично.
        }
    }

    /**
     * Підставляє запамʼятовану адресу в посилання «назад».
     *
     * Посилання приходить із сервера вже готовим — на загальний список
     * обʼєктів. Тому без JavaScript або без памʼяті про перегляд воно теж
     * робоче, просто веде до списку без фільтра.
     *
     * @returns {void}
     */
    function applyOrigin() {
        var links = all('[data-pl-back]');

        if (links.length === 0) {
            return;
        }

        var kind = null;
        var url = null;

        try {
            kind = window.sessionStorage.getItem(RETURN_KIND_KEY);
            url = window.sessionStorage.getItem(RETURN_URL_KEY);
        } catch (e) {
            return;
        }

        links.forEach(function (link) {
            if (kind === 'favorites') {
                var favoritesUrl = link.getAttribute('data-pl-back-favorites');
                var favoritesLabel = link.getAttribute('data-pl-back-favorites-label');
                var label = link.querySelector('[data-pl-back-label]');

                if (favoritesUrl) {
                    link.setAttribute('href', favoritesUrl);
                }

                if (label && favoritesLabel) {
                    label.textContent = favoritesLabel;
                }

                return;
            }

            // Підставляємо лише адреси свого розділу: у sessionStorage могло
            // залишитись щось із іншої сторінки сайту.
            if (url && site.base && url.indexOf(site.base) === 0) {
                link.setAttribute('href', url);
            }
        });
    }


    /* =======================================================================
     *  6. Сортування
     * ======================================================================= */

    /**
     * Власний випадний список сортування.
     *
     * Це саме посилання, а не поле форми: сортування — частина адреси, і
     * кожен його варіант має власне ЧПУ, яке можна надіслати комусь у листі.
     *
     * @returns {void}
     */
    function initSort() {
        var button = document.querySelector('[data-pl-sort]');
        var menu = document.querySelector('[data-pl-sort-menu]');

        if (!button || !menu) {
            return;
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();
            menu.classList.toggle('is-open');
            button.setAttribute('aria-expanded', menu.classList.contains('is-open') ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (menu.contains(event.target) || button.contains(event.target)) {
                return;
            }

            menu.classList.remove('is-open');
            button.setAttribute('aria-expanded', 'false');
        });
    }


    /* =======================================================================
     *  Запуск
     * ======================================================================= */

    function init() {
        // Обране
        initFavoriteButtons();
        initFavoritesPage();
        initFavoritesClear();

        // Фільтр
        initFilterPanel();
        initSelects();
        initFilterDependencies();
        initNumericInputs();

        // Карточки й галерея
        initCarousels(document);
        initGallery();

        // Сторінка публікації
        initMasonry();
        initParallax();
        initMap();

        // Навігація
        rememberOrigin();
        applyOrigin();
        initSort();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());

<?php
/**
 * ============================================================================
 *  Форма фільтра
 * ============================================================================
 *
 *  Звичайна HTML-форма, яка надсилається методом GET. Front controller
 *  перетворює її параметри у ЧПУ і робить редирект — тому фільтр працює
 *  без JavaScript, а адреса завжди виглядає однаково:
 *
 *      /base/operation-sale/type-house/rooms-2,3/priceUsd-50000_150000/
 *
 *  Дві частини
 *  -----------
 *  Видима частина — операція, тип нерухомості й ціна. Це три питання, з
 *  яких починається будь-який пошук нерухомості, і вони стоять на білому
 *  полі по центру екрана.
 *
 *  Решта параметрів лежить у блоці «Більше параметрів». Він позиційований
 *  абсолютно, тому не розсовує сторінку — накриває початок списку — і
 *  закривається кліком поза собою.
 *
 *  Логіка залежностей
 *  ------------------
 *  Показувати «кількість кімнат» для земельної ділянки безглуздо, а «тип
 *  комерції» — для квартири. Тому поля помічені атрибутами:
 *
 *      data-pl-types="apartment house commerce"  для яких типів показуємо
 *      data-pl-strict                            показувати ТІЛЬКИ для них
 *
 *  Без `data-pl-strict` поле видно ще й тоді, коли тип не вибраний зовсім.
 *  Перемикає їх site.js — і заодно вимикає приховані поля, щоб браузер не
 *  надіслав умову, якої відвідувач більше не бачить.
 *
 *  Назви полів
 *  -----------
 *  Мусять збігатися з ключами фільтрів (див. клас Filters): перелік — це
 *  `ключ[]`, діапазон — `ключFrom` і `ключTo`, одиничний вибір — просто
 *  `ключ`.
 *
 *  Списки значень ($facets) беруться з бази: у фільтрі показуємо лише те,
 *  що справді є в обʼєктах. Якщо варіант один — поле не показуємо взагалі.
 *
 *  Доступні змінні:
 *
 *    $query  — поточний запит; $query->selected() позначає вибрані пункти
 *    $facets — значення для списків
 * ============================================================================
 */

// Ключ фільтра ціни залежить від вибраної валюти: priceUsd, priceUah, priceEur.
$priceKey = Plusest\Site\Search\Filters::priceKey($currency);

$metroDistances = (array) $config->get('site.metroDistances', []);

// Скільки полів у блоці «Більше параметрів» уже заповнено — показуємо це
// числом на кнопці, щоб відвідувач бачив: там щось є, варто розгорнути.
$visibleKeys = ['operation', 'type', $priceKey];
$extraCount = 0;

foreach ($query->filters() as $key => $value) {
    if (!in_array($key, $visibleKeys, true)) {
        $extraCount++;
    }
}
?>
<div class="pl-filter-wrap">
    <div class="pl-container">

        <form class="pl-filter" method="get" action="<?= $view->esc($url->base()) ?>" data-pl-filter>

            <?php /* Позначка, за якою front controller розуміє: це надіслана форма. */ ?>
            <input type="hidden" name="filter" value="1">
            <input type="hidden" name="sort" value="<?= $view->esc($query->sort()) ?>">

            <!-- ============ Видима частина: операція, тип, ціна ============ -->
            <div class="pl-filter-main">

                <?php /* Операція та тип нерухомості — одиничний вибір. Пошук
                         «продаж або оренда одночасно» не має сенсу: у цих
                         випадків різні ціни й різні очікування. А ще від
                         одиничного вибору залежать решта полів фільтра. */ ?>
                <div class="pl-field">
                    <label class="pl-field-label" for="plOperation">
                        <?= $view->esc($present->paramLabel('operation')) ?>
                    </label>

                    <select class="pl-select" id="plOperation" name="operation"
                            data-pl-select2 data-pl-search="20">
                        <option value=""><?= $view->esc($view->t('common.all')) ?></option>

                        <?php foreach ($facets['operation'] as $value => $total): ?>
                            <option value="<?= $view->esc($value) ?>"
                                <?= $query->selected('operation', $value) ? 'selected' : '' ?>>
                                <?= $view->esc($present->optionLabel('operation', $value)) ?>
                                (<?= $total ?>)
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div class="pl-field">
                    <label class="pl-field-label" for="plType">
                        <?= $view->esc($present->paramLabel('type')) ?>
                    </label>

                    <select class="pl-select" id="plType" name="type"
                            data-pl-select2 data-pl-search="20" data-pl-type>
                        <option value=""><?= $view->esc($view->t('common.all')) ?></option>

                        <?php foreach ($facets['type'] as $value => $total): ?>
                            <option value="<?= $view->esc($value) ?>"
                                <?= $query->selected('type', $value) ? 'selected' : '' ?>>
                                <?= $view->esc($present->optionLabel('type', $value)) ?>
                                (<?= $total ?>)
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div class="pl-field">
                    <span class="pl-field-label">
                        <?= $view->esc($view->t('filter.price')) ?>,
                        <?= $view->esc($present->currencySymbol()) ?>
                    </span>

                    <div class="pl-range">
                        <input class="pl-input" type="text" inputmode="numeric" data-pl-numeric
                               name="<?= $view->esc($priceKey) ?>From"
                               placeholder="<?= $view->esc($view->t('common.from')) ?>"
                               value="<?= $view->esc($query->bound($priceKey, 'from')) ?>">
                        <span class="pl-range-dash">—</span>
                        <input class="pl-input" type="text" inputmode="numeric" data-pl-numeric
                               name="<?= $view->esc($priceKey) ?>To"
                               placeholder="<?= $view->esc($view->t('common.to')) ?>"
                               value="<?= $view->esc($query->bound($priceKey, 'to')) ?>">
                    </div>
                </div>

                <div class="pl-filter-buttons">
                    <button class="pl-btn pl-btn-outline" type="button"
                            data-pl-more aria-expanded="false">
                        <span><?= $view->esc($view->t('filter.more')) ?></span>

                        <?php if ($extraCount > 0): ?>
                            <span class="pl-tag"><?= (int) $extraCount ?></span>
                        <?php endif ?>

                        <i class="fi fi-ts-angle-small-down pl-more-arrow" aria-hidden="true"></i>
                    </button>

                    <button class="pl-btn pl-btn-primary" type="submit">
                        <?= $view->esc($view->t('filter.apply')) ?>
                    </button>
                </div>

            </div>

            <!-- ============ Блок «Більше параметрів» ============ -->
            <div class="pl-filter-extra" data-pl-extra>
                <div class="pl-filter-extra-grid">

                    <?php /* --- Весь обʼєкт чи його частина ------------------ */ ?>
                    <?php if (count($facets['whole']) > 1): ?>
                        <div class="pl-field">
                            <label class="pl-field-label" for="plWhole">
                                <?= $view->esc($view->t('filter.whole')) ?>
                            </label>

                            <select class="pl-select" id="plWhole" name="whole"
                                    data-pl-select2 data-pl-search="20">
                                <option value=""><?= $view->esc($view->t('common.any')) ?></option>
                                <option value="full" <?= $query->selected('whole', 'full') ? 'selected' : '' ?>>
                                    <?= $view->esc($view->t('filter.wholeFull')) ?>
                                </option>
                                <option value="part" <?= $query->selected('whole', 'part') ? 'selected' : '' ?>>
                                    <?= $view->esc($view->t('filter.wholePart')) ?>
                                </option>
                            </select>
                        </div>
                    <?php endif ?>

                    <?php /* --- Уточнення типу нерухомості -------------------- */ ?>
                    <?php
                    /*
                     * Три однакових поля: тип комерції, призначення ділянки й
                     * тип автомісця. Кожне показується лише для свого типу
                     * нерухомості (тому data-pl-strict), а список значень
                     * і підпис беруться з моделі даних.
                     */
                    $subTypes = [
                        'commerceType' => 'commerce',
                        'landType'     => 'land',
                        'parkingType'  => 'parking',
                    ];
                    ?>

                    <?php foreach ($subTypes as $key => $forType): ?>
                        <?php if (count($facets[$key]) > 1): ?>
                            <div class="pl-field" data-pl-types="<?= $view->esc($forType) ?>" data-pl-strict>
                                <label class="pl-field-label" for="pl<?= $view->esc(ucfirst($key)) ?>">
                                    <?= $view->esc($present->paramLabel($key)) ?>
                                </label>

                                <select class="pl-select" id="pl<?= $view->esc(ucfirst($key)) ?>"
                                        name="<?= $view->esc($key) ?>[]" multiple
                                        data-pl-select2
                                        data-placeholder="<?= $view->esc($view->t('common.any')) ?>">
                                    <?php foreach ($facets[$key] as $value => $total): ?>
                                        <option value="<?= $view->esc($value) ?>"
                                            <?= $query->selected($key, $value) ? 'selected' : '' ?>>
                                            <?= $view->esc($present->optionLabel($key, $value)) ?>
                                            (<?= (int) $total ?>)
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php endif ?>
                    <?php endforeach ?>

                    <div class="pl-filter-extra-grid-break"></div>

                    <?php /* --- Кімнати ---------------------------------------- */ ?>
                    <?php /* Множинний вибір: відвідувач шукає «дво- або
                             трикімнатну», а не «від двох до трьох кімнат».
                             Для ділянки й автомісця поле ховаємо. */ ?>
                    <?php if (count($facets['rooms']) > 1): ?>
                        <div class="pl-field" data-pl-types="apartment house commerce">
                            <label class="pl-field-label" for="plRooms">
                                <?= $view->esc($view->t('filter.rooms')) ?>
                            </label>

                            <select class="pl-select" id="plRooms" name="rooms[]" multiple
                                    data-pl-select2 data-pl-search="20"
                                    data-placeholder="<?= $view->esc($view->t('common.any')) ?>">
                                <?php foreach ($facets['rooms'] as $value => $total): ?>
                                    <option value="<?= (int) $value ?>"
                                        <?= $query->selected('rooms', $value) ? 'selected' : '' ?>>
                                        <?= (int) $value ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    <?php endif ?>

                    <?php /* --- Площі ------------------------------------------- */ ?>
                    <div class="pl-field" data-pl-types="apartment house commerce parking">
                        <span class="pl-field-label"><?= $view->esc($view->t('filter.area')) ?></span>

                        <div class="pl-range">
                            <input class="pl-input" type="text" inputmode="decimal" data-pl-numeric
                                   name="areaTotalFrom"
                                   placeholder="<?= $view->esc($view->t('common.from')) ?>"
                                   value="<?= $view->esc($query->bound('areaTotal', 'from')) ?>">
                            <span class="pl-range-dash">—</span>
                            <input class="pl-input" type="text" inputmode="decimal" data-pl-numeric
                                   name="areaTotalTo"
                                   placeholder="<?= $view->esc($view->t('common.to')) ?>"
                                   value="<?= $view->esc($query->bound('areaTotal', 'to')) ?>">
                        </div>
                    </div>

                    <?php /* Площа ділянки є в ділянок і в приватних будинків. */ ?>
                    <div class="pl-field" data-pl-types="land house">
                        <span class="pl-field-label"><?= $view->esc($view->t('filter.areaLand')) ?></span>

                        <div class="pl-range">
                            <input class="pl-input" type="text" inputmode="decimal" data-pl-numeric
                                   name="areaLandFrom"
                                   placeholder="<?= $view->esc($view->t('common.from')) ?>"
                                   value="<?= $view->esc($query->bound('areaLand', 'from')) ?>">
                            <span class="pl-range-dash">—</span>
                            <input class="pl-input" type="text" inputmode="decimal" data-pl-numeric
                                   name="areaLandTo"
                                   placeholder="<?= $view->esc($view->t('common.to')) ?>"
                                   value="<?= $view->esc($query->bound('areaLand', 'to')) ?>">
                        </div>
                    </div>

                    <?php /* --- Поверхи ----------------------------------------- */ ?>
                    <?php /* Для земельної ділянки поверхів не буває. */ ?>
                    <div class="pl-field" data-pl-types="apartment commerce parking">
                        <span class="pl-field-label"><?= $view->esc($view->t('filter.floor')) ?></span>

                        <div class="pl-range">
                            <input class="pl-input" type="text" inputmode="numeric" data-pl-numeric
                                   name="floorFrom"
                                   placeholder="<?= $view->esc($view->t('common.from')) ?>"
                                   value="<?= $view->esc($query->bound('floor', 'from')) ?>">
                            <span class="pl-range-dash">—</span>
                            <input class="pl-input" type="text" inputmode="numeric" data-pl-numeric
                                   name="floorTo"
                                   placeholder="<?= $view->esc($view->t('common.to')) ?>"
                                   value="<?= $view->esc($query->bound('floor', 'to')) ?>">
                        </div>
                    </div>

                    <div class="pl-field" data-pl-types="apartment house commerce parking">
                        <span class="pl-field-label"><?= $view->esc($view->t('filter.floors')) ?></span>

                        <div class="pl-range">
                            <input class="pl-input" type="text" inputmode="numeric" data-pl-numeric
                                   name="floorsFrom"
                                   placeholder="<?= $view->esc($view->t('common.from')) ?>"
                                   value="<?= $view->esc($query->bound('floors', 'from')) ?>">
                            <span class="pl-range-dash">—</span>
                            <input class="pl-input" type="text" inputmode="numeric" data-pl-numeric
                                   name="floorsTo"
                                   placeholder="<?= $view->esc($view->t('common.to')) ?>"
                                   value="<?= $view->esc($query->bound('floors', 'to')) ?>">
                        </div>
                    </div>

                    <div class="pl-filter-extra-grid-break"></div>

                    <?php /* --- Новобудова -------------------------------------- */ ?>
                    <?php
                    /*
                     * Два різних питання, які часто плутають: newBuilding —
                     * «дім новий», commissioning — «його вже здали в
                     * експлуатацію». Для ділянок і автомість не показуємо.
                     */
                    foreach (['newBuilding', 'commissioning'] as $key):
                        ?>
                        <?php if (count($facets[$key]) > 1): ?>
                        <div class="pl-field" data-pl-types="apartment house commerce"<?php if ($key === 'commissioning'): ?> data-pl-commissioning<?php endif ?>>
                            <label class="pl-field-label" for="pl<?= $view->esc(ucfirst($key)) ?>">
                                <?= $view->esc($present->paramLabel($key)) ?>
                            </label>

                            <select class="pl-select" id="pl<?= $view->esc(ucfirst($key)) ?>"
                                    name="<?= $view->esc($key) ?>" data-pl-select2 data-pl-search="20">
                                <option value=""><?= $view->esc($view->t('common.any')) ?></option>
                                <option value="yes" <?= $query->selected($key, 'yes') ? 'selected' : '' ?>>
                                    <?= $view->esc($view->t('common.yes')) ?>
                                </option>
                                <option value="no" <?= $query->selected($key, 'no') ? 'selected' : '' ?>>
                                    <?= $view->esc($view->t('common.no')) ?>
                                </option>
                            </select>
                        </div>
                    <?php endif ?>
                    <?php endforeach ?>

                    <?php /* --- Локація ----------------------------------------- */ ?>
                    <?php if (count($facets['cities']) > 1): ?>
                        <div class="pl-field">
                            <label class="pl-field-label" for="plCity">
                                <?= $view->esc($view->t('filter.city')) ?>
                            </label>

                            <select class="pl-select" id="plCity" name="cityId[]" multiple
                                    data-pl-select2 data-pl-search="0"
                                    data-placeholder="<?= $view->esc($view->t('common.all')) ?>">
                                <?php foreach ($facets['cities'] as $city): ?>
                                    <option value="<?= $view->esc($city['id']) ?>"
                                        <?= $query->selected('cityId', $city['id']) ? 'selected' : '' ?>>
                                        <?= $view->esc($lang === 'ru' && $city['nameRu'] !== null ? $city['nameRu'] : $city['nameUk']) ?>
                                        (<?= (int) $city['total'] ?>)
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    <?php endif ?>

                    <?php if ($facets['districts'] !== []): ?>
                        <div class="pl-field">
                            <label class="pl-field-label" for="plDistrict">
                                <?= $view->esc($view->t('filter.district')) ?>
                            </label>

                            <select class="pl-select" id="plDistrict" name="districtId[]" multiple
                                    data-pl-select2 data-pl-search="0"
                                    data-placeholder="<?= $view->esc($view->t('common.all')) ?>">
                                <?php foreach ($facets['districts'] as $district): ?>
                                    <option value="<?= $view->esc($district['id']) ?>"
                                        <?= $query->selected('districtId', $district['id']) ? 'selected' : '' ?>>
                                        <?= $view->esc($lang === 'ru' && $district['nameRu'] !== null ? $district['nameRu'] : $district['nameUk']) ?>
                                        (<?= (int) $district['total'] ?>)
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    <?php endif ?>

                    <?php /* Метро є лише в Києві, Харкові та Дніпрі, тому список
                             показуємо тільки коли станції справді знайшлися. */ ?>
                    <?php if ($facets['metro'] !== []): ?>
                        <div class="pl-field">
                            <label class="pl-field-label" for="plMetro">
                                <?= $view->esc($view->t('filter.metro')) ?>
                            </label>

                            <select class="pl-select" id="plMetro" name="metroId[]" multiple
                                    data-pl-select2 data-pl-search="0"
                                    data-placeholder="<?= $view->esc($view->t('common.all')) ?>">
                                <?php foreach ($facets['metro'] as $station): ?>
                                    <option value="<?= $view->esc($station['metroId']) ?>"
                                        <?= $query->selected('metroId', $station['metroId']) ? 'selected' : '' ?>>
                                        <?= $view->esc($lang === 'ru' && $station['nameRu'] !== null ? $station['nameRu'] : $station['nameUk']) ?>
                                        (<?= (int) $station['total'] ?>)
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>

                        <?php if ($metroDistances !== []): ?>
                            <div class="pl-field">
                                <label class="pl-field-label" for="plMetroDistance">
                                    <?= $view->esc($view->t('filter.metroDistance')) ?>
                                </label>

                                <select class="pl-select" id="plMetroDistance" name="metroDistance"
                                        data-pl-select2 data-pl-search="20">
                                    <option value=""><?= $view->esc($view->t('common.all')) ?></option>

                                    <?php foreach ($metroDistances as $distance): ?>
                                        <option value="<?= (int) $distance ?>"
                                            <?= $query->selected('metroDistance', $distance) ? 'selected' : '' ?>>
                                            <?= (int) $distance ?> <?= $view->esc($view->t('common.meters')) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php endif ?>
                    <?php endif ?>

                </div>

                <div class="pl-filter-extra-foot">
                    <?php if ($query->count() > 0): ?>
                        <span class="pl-small pl-muted">
                            <?= $view->esc($view->t('filter.selected', ['count' => $query->count()])) ?>
                        </span>

                        <a class="pl-btn pl-btn-ghost" href="<?= $view->esc($url->search($query->reset())) ?>">
                            <?= $view->esc($view->t('filter.reset')) ?>
                        </a>
                    <?php endif ?>

                    <button class="pl-btn pl-btn-primary" type="submit">
                        <?= $view->esc($view->t('filter.apply')) ?>
                    </button>
                </div>
            </div>

        </form>

    </div>
</div>

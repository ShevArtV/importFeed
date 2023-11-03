<?php
// php -d display_errors -d error_reporting=E_ALL ~/stroybat23/public_html/core/elements/importfeed.class.php
// /usr/local/php/php-7.4/bin/php -d display_errors -d error_reporting=E_ALL art-sites.ru/htdocs/www/core/elements/importfeed.class.php
return [
    'importStep' => 100,
    'feedUrl' => '', // ссылка на фид
    'feedPath' => 'test.xml', // путь к файлу фида на сервере, указывать от корня сайта
    'imagePath' => 'assets/img/', // путь для загрузки картинок, указывать от корня сайта
    'importCategories' => true, // импортировать категории?
    'importProducts' => true, // импортировать товары?
    'importProductsMode' => 'create', // возможные значения create - только создание новых; update - обновление существующих; пустая строка - создание и обновление.
    'createUniquePagetitle' => false, // создавать уникальный pagetitle? Отменяет следующую настройку createUniqueAlias
    'createUniqueAlias' => true, // добавить id к псевдониму?
    'saveAlias' => false, // сохранить псевдоним?
    'removeEmpty' => true, // удалять свойства с пустыми значениями?
    'setGallery' => true, // установить галерею товара?
    'setOptions' => true, // установить опции товара?
    'removeOldFiles' => false, // очистить галерею перед добавлением новых фото?
    'allowDownloadImages' => true, // разрешить загрузку картинок из удалённого источника?
    'optionsCategoryId' => '83', // id категории (группы) для опций товаров (категория должна быть создана на сайте)
    'productConditions' => ['pagetitle' => 'pagetitle'], // условия проверки существования товара, допускается использовать только основные поля ресурса и товара, для полей товара нужно использовать префикс Data.fieldname
    'categoryConditions' => ['pagetitle' => 'pagetitle'], // условия проверки существования категории, допускается использовать только основные поля ресурса
    'ignore_params' => [
        'Акционный текст',
        'Дата поступления',
        'Сертификаты'
    ], // какие параметры проигнорировать?
    'params_relations' => [
        //'Гарантия на раму' => 'garantiya',
    ], // какие параметры с какими уже имеющимися на сайте опциями соотнести? Название param как есть в YML сопоставляем по ключу опции на сайте
    'truncated' => [
        'pagetitle' => 90,
        'longtitle' => '',
        'introtext' => '',
        'description' => '',
    ], // список основных полей ресурса и их максимальная длина, если хотите её ограничить
    'categoryDefaultFields' => [
        'parent' => 173,
        'template' => 17,
        'hidemenu' => 1,
        'published' => 1,
        'class_key' => 'msCategory'
    ], // общие поля категорий
    'productDefaultFields' => [
        'parent' => 173,
        'template' => 15,
        'hidemenu' => 1,
        'published' => 1,
        'show_in_tree' => 0,
        'class_key' => 'msProduct',
        'supplier' => '', // позволяет независимо обновлять товары от разных поставщиков, дополнительно необходимо создать у товара поле import_status
        'source' => 2
    ], // общие поля товара
    'productFields' => [
        'weight' => 'name_Вес',
        'pagetitle' => 'name',
        'content' => 'description',
        'made_in' => 'country_of_origin',
        'price' => 'price',
        'old_price' => 'oldprice',
        'type' => 'option_type'
    ], // сопоставление полей товара на сайте полям в файле
    'vendorFields' => [
        'name' => 'vendor',
        'resource' => '',
        'country' => '',
        'logo' => 'name_ЛогоВендора',
        'address' => '',
        'phone' => '',
        'fax' => '',
        'email' => '',
        'description' => 'vendorCode',
        'properties' => ''
    ] // сопоставление полей производителя на сайте полям в файле
];
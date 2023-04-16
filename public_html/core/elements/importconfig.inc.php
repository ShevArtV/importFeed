<?php
// php -d display_errors -d error_reporting=E_ALL ~/stroybat23/public_html/core/elements/importfeed.class.php
return [
    'rootCatalogId' => 8, // ID корневого каталога
    'productTemplateId' => 10, // ID шаблона товара
    'feedUrl' => '', // ссылка на фид
    'feedPath' => 'import.xml', // путь к файлу фида на сервере, указывать от корня сайта
    'imagePath' => 'assets/img/', // путь для загрузки картинок, указывать от корня сайта
    'importCategories' => true, // импортировать категории?
    'importProducts' => true, // импортировать товары?
    'createUniquePagetitle' => false, // создавать уникальный pagetitle?
    'saveAlias' => true, // сохранить псевдоним?
    'removeEmpty' => true, // удалять свойства с пустыми значениями?
    'setGallery' => true, // установить галерею товара?
    'setOptions' => true, // установить опции товара?
    'gallerySource' => 2, // удалять свойства с пустыми значениями?
    'removeOldFiles' => true, // очистить галерею?
    'truncated' => [
        'pagetitle' => 90,
        'longtitle' => '',
        'introtext' => '',
        'description' => '',
    ], // список основных полей ресурса и их максимальная длина
    'categoryDefaultFields' => [
        'parent' => 8,
        'template' => 8,
        'hidemenu' => 1,
        'published' => 1,
        'class_key' => 'msCategory'
    ], // общие поля категорий
    'productDefaultFields' => [
        'parent' => 8,
        'template' => 10,
        'hidemenu' => 1,
        'published' => 1,
        'show_in_tree' => 0,
        'class_key' => 'msProduct',
        'source' => 2
    ], // общие поля товара
    'productFields' => [
        'weight' => 'name_Вес',
        'pagetitle' => 'name',
        'content' => 'description',
        'made_in' => 'country_of_origin',
        'price' => 'price',
        'old_price' => 'oldprice'
    ],
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
    ]
];
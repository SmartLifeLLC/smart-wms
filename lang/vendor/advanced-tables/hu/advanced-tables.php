<?php

return [

    'forms' => [

        'heading' => 'Új nézet',
        'name' => 'Megnevezés',
        'user' => 'Tulajdonos',
        'resource' => 'Erőforrás',
        'note' => 'Jegyzet',

        'status' => [

            'label' => 'Állapot',

        ],

        'name' => [

            'label' => 'Megnevezés',
            'helper_text' => 'Válassz egy rövid, de könnyen azonosítható megnevezést a nézethez',

        ],

        'filters' => [

            'label' => 'Nézet szűrők',
            'helper_text' => 'Ezek a szűrők el lesznek mentve a nézethez',

        ],

        'panels' => [

            'label' => 'Panelek',

        ],

        'preset_view' => [

            'label' => 'Előre beállított nézet',
            'query_label' => 'Előre beállított nézet lekérdezés',
            'helper_text_start' => 'Az előre beállított nézetet használod ',
            'helper_text_end' => ' alapként ehhez a nézethez. Az előre beállított nézetek saját, független beállításokkal rendelkezhetnek az általad választottakon felül.',

        ],

        'icon' => [

            'label' => 'Ikon',
            'placeholder' => 'Válassz egy ikont',

        ],

        'color' => [

            'label' => 'Szín',

        ],

        'public' => [

            'label' => 'Nyilvánossá tétel',
            'toggle_label' => 'Nyilvános',
            'helper_text' => 'Tedd ezt a nézetet elérhetővé minden felhasználó számára',

        ],

        'favorite' => [

            'label' => 'Hozzáadás a kedvencekhez',
            'toggle_label' => 'Kedvencem',
            'helper_text' => 'Add hozzá ezt a nézetet a kedvenceidhez',

        ],

        'global_favorite' => [

            'label' => 'Globális kedvenccé tétel',
            'toggle_label' => 'Globális kedvenc',
            'helper_text' => 'Add hozzá ezt a nézetet minden felhasználó kedvenclistájához',

        ],

    ],

    'quick_filters' => [

        'more_indicator_labels' => '& :count tovább',

    ],

    'multi_sort' => [

        'label' => 'Több rendezés szerint',
        'add_column_label' => 'Oszlop hozzáadása',
        'reset_label' => 'Visszaállítás',

    ],

    'notifications' => [

        'preset_views' => [

            'title' => 'Nem lehet létrehozni a nézetet',
            'body' => 'Új nézetek nem hozhatók létre előre beállított nézetből. Kérjük, építsd fel a nézeted az Alapértelmezett nézet vagy bármely felhasználói nézet segítségével.',

        ],

        'save_view' => [

            'saved' => [

                'title' => 'Mentve',

            ],

        ],

        'edit_view' => [

            'saved' => [

                'title' => 'Mentve',

            ],

        ],

        'replace_view' => [

            'replaced' => [

                'title' => 'Cserélve',

            ],

        ],

    ],

    'quick_save' => [

        'save' => [

            'modal_heading' => 'Nézet mentése',
            'submit_label' => 'Nézet mentése',

        ],

    ],

    'select' => [

        'label' => 'Nézetek',
        'placeholder' => 'Válassz nézetet',

    ],

    'status' => [

        'approved' => 'jóváhagyva',
        'pending' => 'függőben',
        'rejected' => 'elutasítva',

    ],

    'tables' => [

        'favorites' => [

            'default' => 'Alapértelmezett',

        ],

        'columns' => [

            'user' => 'Tulajdonos',
            'icon' => 'Ikon',
            'color' => 'Szín',
            'name' => 'Nézet neve',
            'panel' => 'Panel',
            'resource' => 'Erőforrás',
            'status' => 'Állapot',
            'filters' => 'Szűrők',
            'is_public' => 'Nyilvános',
            'is_user_favorite' => 'Kedvencem',
            'is_global_favorite' => 'Globális',
            'sort_order' => 'Rendezési sorrend',
            'users_favorite_sort_order' => 'Kedvenc rendezési sorrend',

        ],

        'tooltips' => [

            'is_user_favorite' => [

                'unfavorite' => 'Eltávolítás a kedvencekből',
                'favorite' => 'Kedvenccé tétel',

            ],

            'is_public' => [

                'make_private' => 'Tedd priváttá',
                'make_public' => 'Tedd nyilvánossá',

            ],

            'is_global_favorite' => [

                'make_personal' => 'Tedd személyessé',
                'make_global' => 'Tedd globálissá',

            ],

        ],

        'actions' => [

            'buttons' => [

                'open' => 'Megnyitás',
                'approve' => 'Jóváhagyás',

            ],

        ],

    ],

    'toggled_columns' => [

        'visible' => 'Látható',
        'hidden' => 'Rejtett',
        'enable_all' => 'Összes engedélyezése',

    ],

    'user_view_resource' => [

        'model_label' => 'Nézet', // 'Felhasználói nézet',
        'plural_model_label' => 'Nézetek', // 'Felhasználói nézetek',
        'navigation_label' => 'Nézetek', // 'Felhasználói nézetek',

    ],

    'view_manager' => [

        'actions' => [

            'add_view_to_favorites' => 'Hozzáadás a kedvencekhez',
            'set_as_managed_default_view' => 'Beállítás alapértelmezettként',
            'remove_as_managed_default_view' => 'Alapértelmezett eltávolítása',
            'apply_view' => 'Nézet alkalmazása',
            'save' => 'Mentés',
            'save_view' => 'Nézet mentése',
            'delete_view' => 'Nézet törlése',
            'delete_view_description' => 'Ez a nézet egy :type nézet. Más felhasználók elveszítik a hozzáférést ehhez a nézethez. Biztosan folytatni szeretnéd?',
            'delete_view_modal_submit_label' => 'Törlés',
            'remove_view_from_favorites' => 'Eltávolítás a kedvencekből',
            'edit_view' => 'Nézet szerkesztése',
            'replace_view' => 'Nézet cseréje',
            'replace_view_modal_description' => 'Ezt a nézetet a táblázat aktuális konfigurációjával fogod cserélni. Biztosan folytatni szeretnéd?',
            'replace_view_modal_submit_label' => 'Csere',
            'show_view_manager' => 'Nézetkezelő megjelenítése',

        ],

        'badges' => [

            'active' => 'aktív',
            'preset' => 'előre beállított',
            'user' => 'felhasználói',
            'global' => 'globális',
            'public' => 'nyilvános',

        ],

        'heading' => 'Nézetkezelő',

        'table_heading' => 'Nézetek',

        'no_views' => 'Nincs nézet',

        'subheadings' => [

            'user_favorites' => 'Felhasználói kedvencek',
            'user_views' => 'Felhasználói nézetek',
            'preset_views' => 'Előre beállított nézetek',
            'global_views' => 'Globális nézetek',
            'public_views' => 'Nyilvános nézetek',

        ],

    ],
];

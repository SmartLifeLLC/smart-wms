<?php

return [

    'form' => [

        'add_filter' => 'Szűrő hozzáadása',
        'expand_view' => 'Kibontás',
        'new_filter_group' => 'Új szűrőcsoport',
        'or' => 'vagy',
        'remove_filter' => 'Eltávolítás',
        'recent' => 'Legutóbbi',
        'relative' => 'Relatív',
        'absolute' => 'Abszolút',
        'empty' => 'Üres',

    ],

    'filters' => [

        'indicator_name' => 'Szűrőcsoport',

        'operators' => [

            'and' => 'és',
            'or' => 'vagy',

        ],

        'numeric' => [

            'equal_to' => [
                'indicator' => 'egyenlő',
                'option' => 'egyenlő',
            ],

            'not_equal_to' => [
                'indicator' => 'nem egyenlő',
                'option' => 'nem egyenlő',
            ],

            'greater_than' => [
                'indicator' => 'nagyobb mint',
                'option' => 'nagyobb mint',
            ],

            'greater_than_or_equal_to' => [
                'indicator' => 'nagyobb vagy egyenlő',
                'option' => 'nagyobb vagy egyenlő',
            ],

            'less_than' => [
                'indicator' => 'kisebb mint',
                'option' => 'kisebb mint',
            ],

            'less_than_or_equal_to' => [
                'indicator' => 'kisebb vagy egyenlő',
                'option' => 'kisebb vagy egyenlő',
            ],

            'between' => [
                'indicator' => 'között',
                'option' => 'között',
            ],

            'not_between' => [
                'indicator' => 'nem között',
                'option' => 'nem között',
            ],

            'positive' => [
                'indicator' => 'pozitív',
                'option' => 'pozitív',
            ],

            'negative' => [
                'indicator' => 'negatív',
                'option' => 'negatív',
            ],

        ],

        'text' => [

            'is' => [
                'indicator' => 'megegyezik',
                'option' => 'megegyezik',
            ],

            'is_not' => [
                'indicator' => 'nem egyezik',
                'option' => 'nem egyezik',
            ],

            'starts_with' => [
                'indicator' => 'kezdődik',
                'option' => 'kezdődik',
            ],

            'does_not_start_with' => [
                'indicator' => 'nem kezdődik',
                'option' => 'nem kezdődik',
            ],

            'ends_with' => [
                'indicator' => 'végződik',
                'option' => 'végződik',
            ],

            'does_not_end_with' => [
                'indicator' => 'nem végződik',
                'option' => 'nem végződik',
            ],

            'contains' => [
                'indicator' => 'tartalmazza',
                'option' => 'tartalmazza',
            ],

            'does_not_contain' => [
                'indicator' => 'nem tartalmazza',
                'option' => 'nem tartalmazza',
            ],

            'is_empty' => [
                'indicator' => 'üres',
                'option' => 'üres',
            ],

            'is_not_empty' => [
                'indicator' => 'nem üres',
                'option' => 'nem üres',
            ],

        ],

        'date' => [

            'yesterday' => [
                'indicator' => 'tegnap',
                'option' => 'tegnap',
            ],

            'today' => [
                'indicator' => 'ma',
                'option' => 'ma',
            ],

            'tomorrow' => [
                'indicator' => 'holnap',
                'option' => 'holnap',
            ],

            'in_this' => [
                'indicator' => 'ebben',
                'option' => 'ebben',
            ],

            'is_next' => [
                'indicator' => 'következő',
                'option' => 'következő',
            ],

            'is_last' => [
                'indicator' => 'előző',
                'option' => 'előző',
            ],

            'in_the_next' => [
                'indicator' => 'a következő',
                'option' => 'a következő',
            ],

            'in_the_last' => [
                'indicator' => 'az előző',
                'option' => 'az előző',
            ],

            'exactly' => [
                'indicator' => 'pontosan',
                'option' => 'pontosan',
            ],

            'before' => [
                'indicator' => 'előtt',
                'option' => 'előtt',
            ],

            'after' => [
                'indicator' => 'után',
                'option' => 'után',
            ],

            'between' => [
                'indicator' => 'között',
                'option' => 'között',
            ],

            'is_date' => [
                'indicator' => 'dátum',
                'option' => 'a dátum',
            ],

            'before_date' => [
                'indicator' => 'előtt',
                'option' => 'dátum előtt',
            ],

            'after_date' => [
                'indicator' => 'után',
                'option' => 'dátum után',
            ],

            'between_dates' => [
                'indicator' => 'között',
                'option' => 'dátumok között',
            ],

            'is_empty' => [
                'indicator' => 'üres',
                'option' => 'üres',
            ],

            'is_not_empty' => [
                'indicator' => 'nem üres',
                'option' => 'nem üres',
            ],

            'unit' => [
                'week' => [
                    'indicator_singular' => 'hét',
                    'indicator' => 'hét',
                    'option' => 'hét',
                ],

                'month' => [
                    'indicator_singular' => 'hónap',
                    'indicator' => 'hónap',
                    'option' => 'hónap',
                ],

                'quarter' => [
                    'indicator_singular' => 'negyedév',
                    'indicator' => 'negyedév',
                    'option' => 'negyedév',
                ],

                'year' => [
                    'indicator_singular' => 'év',
                    'indicator' => 'év',
                    'option' => 'év',
                ],

                'days' => [
                    'indicator_singular' => 'nap',
                    'indicator' => 'napok',
                    'option' => 'napok',
                ],

                'weeks' => [
                    'indicator_singular' => 'hét',
                    'indicator' => 'hetek',
                    'option' => 'hetek',
                ],

                'months' => [
                    'indicator_singular' => 'hónap',
                    'indicator' => 'hónapok',
                    'option' => 'hónapok',
                ],

                'quarters' => [
                    'indicator_singular' => 'negyedév',
                    'indicator' => 'negyedévek',
                    'option' => 'negyedévek',
                ],

                'years' => [
                    'indicator_singular' => 'év',
                    'indicator' => 'évek',
                    'option' => 'évek',
                ],

                'days_ago' => [
                    'indicator_singular' => 'napja',
                    'indicator' => 'napja',
                    'option' => 'napja',
                ],

                'days_from_now' => [
                    'indicator_singular' => 'nap múlva',
                    'indicator' => 'nap múlva',
                    'option' => 'nap múlva',
                ],

                'weeks_ago' => [
                    'indicator_singular' => 'hete',
                    'indicator' => 'hete',
                    'option' => 'hete',
                ],

                'weeks_from_now' => [
                    'indicator_singular' => 'hét múlva',
                    'indicator' => 'hetek múlva',
                    'option' => 'hetek múlva',
                ],

                'months_ago' => [
                    'indicator_singular' => 'hónapja',
                    'indicator' => 'hónapja',
                    'option' => 'hónapja',
                ],

                'months_from_now' => [
                    'indicator_singular' => 'hónap múlva',
                    'indicator' => 'hónap múlva',
                    'option' => 'hónap múlva',
                ],

                'quarters_ago' => [
                    'indicator_singular' => 'negyedéve',
                    'indicator' => 'negyedéve',
                    'option' => 'negyedéve',
                ],

                'quarters_from_now' => [
                    'indicator_singular' => 'negyedév múlva',
                    'indicator' => 'negyedévek múlva',
                    'option' => 'negyedévek múlva',
                ],

                'years_ago' => [
                    'indicator_singular' => 'éve',
                    'indicator' => 'éve',
                    'option' => 'éve',
                ],

                'years_from_now' => [
                    'indicator_singular' => 'év múlva',
                    'indicator' => 'év múlva',
                    'option' => 'év múlva',
                ],

            ],

        ],

    ],

];

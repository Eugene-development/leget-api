<?php

/**
 * Template registry.
 *
 * Each entry describes a template available in the platform.
 * The key is the templateId stored in the licenses table.
 *
 * pages: map of page slug → ordered list of components.
 *   Each component has:
 *     - type:     component name (must match Svelte pageOverrides key)
 *     - defaults: default data seeded into page_components for new clients
 *
 * The 'defaults' are copied into each client's page_components row on page
 * creation. Clients can later edit their own copy independently.
 */
return [

    1 => [
        'name'  => 'Promo-1',
        'header' => [
            'siteName' => 'Новострой',
        ],
        'footer' => [
            'siteName' => 'Новострой',
        ],
        'pages' => [
            // Зарезервированная «глобальная» страница — контейнер для компонентов,
            // общих для всех страниц сайта (футер). Не маршрутизируется; RenderPage
            // дописывает её компоненты в componentsData каждой страницы.
            '__global__' => [
                ['type' => 'Footer', 'defaults' => [
                    'siteName' => 'Логотип',
                    // phone/email/address/hours сознательно опускаем —
                    // отображаются фолбэки ?? из Svelte-компонента; «Сброс» вернёт сюда.
                ]],
            ],
            // Страница 404. Отдаётся и по прямому адресу /404, и как тело ответа
            // на любой ненайденный слаг — leget-main запрашивает её после
            // PAGE_NOT_FOUND и отвечает её разметкой со статусом 404.
            '/404' => [
                ['type' => 'NotFound', 'defaults' => [
                    'title'          => 'Страница не найдена',
                    'subtitle'       => 'Возможно, страница была перемещена или удалена, а ссылка устарела. Вернитесь на главную или к предыдущему разделу.',
                    'homeButtonText' => 'На главную',
                    'homeHref'       => '/',
                    'backButtonText' => 'Вернуться назад',
                ]],
            ],
            '/' => [
                ['type' => 'HeroMain',   'defaults' => ['companyName' => 'Компания', 'title' => 'Мебель и Техника', 'description' => 'Эксклюзивная корпусная мебель по вашим индивидуальным размерам с бесплатным 3D-проектом от профессионального дизайнера, подбором премиальных материалов и расчётом стоимости под ваш бюджет.', 'buttonText' => 'Дизайн-проект с расчётом стоимости', 'buttonHref' => '/contact']],
                ['type' => 'Message',    'defaults' => ['text' => 'Предлагаем мебель на заказ для вашего идеального интерьера с индивидуальным подбором размеров и дизайна.']],
                ['type' => 'PromoOffer', 'defaults' => ['badge' => 'Эксклюзивное предложение', 'title' => 'Специальное предложение', 'textPrimary' => 'Скидка 15% на мебель этой весной', 'textSecondary' => 'Воспользуйтесь уникальной возможностью приобрести качественную мебель по выгодной цене', 'primaryButton' => 'Получить предложение', 'secondaryButton' => 'Узнать больше', 'primaryHref' => '/contact', 'secondaryHref' => '/about']],
                ['type' => 'Equipment',  'defaults' => ['badge' => 'Дополнительно', 'title' => 'Комплектация проектов']],
                ['type' => 'Stage',      'defaults' => ['badge' => 'Это важно', 'title' => 'Наша работа', 'description' => 'Мы поддержим вас на всех этапах работы над мебельным проектом: от первой консультации до дня финальной сборки.']],
                ['type' => 'Incentives', 'defaults' => [
                    'badge' => 'Выгода',
                    'title' => 'С нами выгодно',
                    'text' => '<p>Помогаем выбрать фурнитуру, материалы, цветовые сочетания и производителя мебели — чтобы вы получили честную цену, соблюдение сроков, высокое качество и внимательный сервис без лишних затрат времени и бюджета.</p>',
                    'gallery' => [
                        [
                            'src' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/incentives/price.webp',
                            'alt' => 'Образцы материалов и расчёт стоимости мебели',
                            'label' => 'Цена',
                        ],
                        [
                            'src' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/incentives/timelines.webp',
                            'alt' => 'Монтаж мебели точно в срок',
                            'label' => 'Сроки',
                        ],
                        [
                            'src' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/incentives/quality-v2.webp',
                            'alt' => 'Ровные фасады и точная подгонка деталей мебели',
                            'label' => 'Качество',
                        ],
                        [
                            'src' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/incentives/service.webp',
                            'alt' => 'Консультация дизайнера с клиентом',
                            'label' => 'Сервис',
                        ],
                    ],
                ]],
                ['type' => 'Direction',  'defaults' => []],
                ['type' => 'Brands',     'defaults' => ['badge' => 'Материалы', 'title' => 'Бренды, говорящие о качестве', 'partnersLabel' => 'Наши партнёры-производители']],
            ],
            '/about' => [
                ['type' => 'Hero',       'defaults' => ['badge' => 'О компании', 'title' => 'О нас', 'lead' => 'Мы помогаем пройти путь от идеи до готового интерьера: подбираем материалы, считаем проект и ведём его до финальной сборки.', 'text' => '', 'buttonText' => 'Связаться с нами', 'img1' => '', 'img2' => '', 'img3' => '', 'img4' => '']],
                ['type' => 'Text',       'defaults' => ['content' => '<p>Мы делаем мебель под конкретное помещение и конкретных людей: сначала замер и разговор о том, как вы живёте и что храните, потом дизайн-проект, расчёт и подбор материалов под ваш бюджет.</p><p>Ведём проект до конца — согласуем детали, держим сроки и остаёмся на связи после сборки.</p>']],
                ['type' => 'Statistics', 'defaults' => []],
                ['type' => 'Mission',    'defaults' => ['title' => 'Наша миссия', 'text1' => '', 'text2' => '', 'text3' => '', 'imageUrl' => '']],
                ['type' => 'Values',     'defaults' => ['title' => 'Наши ценности', 'subtitle' => 'Принципы, которыми мы руководствуемся в работе', 'items' => []]],
                ['type' => 'WhyUs',      'defaults' => ['title' => 'Почему выбирают нас', 'subtitle' => 'Преимущества работы с нами', 'items' => []]],
                ['type' => 'AboutCTA',   'defaults' => ['title' => 'Ждём вас в качестве клиента', 'subtitle' => 'Свяжитесь с нами, чтобы обсудить ваш проект', 'buttonText' => 'Получить консультацию', 'phone' => '']],
            ],
            '/contact' => [
                ['type' => 'ContactForm', 'defaults' => ['title' => 'Контакты', 'email' => 'info@example.com']],
            ],
            '/actions' => [
                ['type' => 'Hero',              'defaults' => ['eyebrow' => 'Выгодные предложения', 'title' => 'Акции, скидки и подарки', 'subtitle' => 'Получите актуальные акции наших партнёров и узнайте о подарках и скидках на проекты и услуги']],
                ['type' => 'ActionsCards',      'defaults' => []],
                ['type' => 'ActionsCardsExtra', 'defaults' => []],
                ['type' => 'ActionsBanner',     'defaults' => ['imageUrl' => '', 'imageAlt' => 'Акция']],
                ['type' => 'ActionsCTA',        'defaults' => ['title' => 'Хотите узнать больше об акциях?', 'subtitle' => 'Свяжитесь с нами и мы расскажем обо всех актуальных предложениях', 'buttonText' => 'Связаться с нами', 'phone' => '']],
            ],
            '/contacts' => [
                ['type' => 'ContactsHero',      'defaults' => ['eyebrow' => 'Связь с нами', 'title' => 'Контакты', 'subtitle' => 'Мы работаем с понедельника по субботу с 10:00 до 20:00. В иное время воспользуйтесь онлайн-чатом или почтой.']],
                ['type' => 'ContactChannels',   'defaults' => ['phone' => '', 'email' => '']],
                ['type' => 'ContactAddress',    'defaults' => ['eyebrow' => 'Давайте встретимся', 'title' => 'Личная консультация', 'description' => 'Для обсуждения деталей мы можем организовать с вами встречу в одном из салонов наших партнёров или на вашем объекте', 'buttonText' => 'Записаться на консультацию', 'addressTitle' => 'Адрес', 'addressText' => '', 'hoursTitle' => 'Часы работы', 'hoursText' => '', 'parkingTitle' => 'Парковка', 'parkingText' => '', 'mapImageUrl' => '']],
                ['type' => 'ContactMessengers', 'defaults' => ['eyebrow' => 'Социальные сети', 'title' => 'Мы в Телеграм', 'subtitle' => 'Напишите нам и мы ответим в течение нескольких минут', 'telegramUrl' => '']],
                ['type' => 'ContactCTA',        'defaults' => ['title' => 'Остались вопросы?', 'subtitle' => 'Свяжитесь с нами любым удобным способом — мы всегда рады помочь', 'phone' => '', 'telegramUrl' => '']],
            ],
            '/partnership' => [
                ['type' => 'PartnershipHero',    'defaults' => ['badge' => 'Партнёрская программа', 'title' => 'Растём вместе', 'text' => 'Приглашаем к сотрудничеству дизайнеров интерьеров, ремонтные бригады и продавцов мебели. Выгодные условия и прозрачная система вознаграждений.', 'buttonText' => 'Обсудить сотрудничество', 'quote' => 'Партнёрство открывает новые горизонты и возможности для совместного роста. Вместе мы достигнем большего.', 'quoteName' => '', 'quoteRole' => '']],
                ['type' => 'WhoWeInvite',        'defaults' => ['title' => 'Кого мы приглашаем', 'subtitle' => 'Партнёрство для профессионалов в сфере интерьера и ремонта', 'cards' => [], 'platformText' => 'Профессиональная платформа для автоматизации партнёрских продаж.', 'platformUrl' => '', 'platformButtonText' => 'Перейти на платформу']],
                ['type' => 'ForManufacturers',   'defaults' => ['badge' => 'Вы организация?', 'title' => 'Для производителей и поставщиков', 'text' => 'Мы готовы предложить уникальные возможности для совместного развития.', 'buttonText' => 'Оставить заявку', 'benefits' => [], 'stats' => []]],
                ['type' => 'Benefits',           'defaults' => ['title' => 'Преимущества партнёрства', 'subtitle' => 'Что вы получаете, работая с нами', 'items' => []]],
                ['type' => 'HowToStart',         'defaults' => ['title' => 'Как стать партнёром', 'subtitle' => 'Простой процесс от первого контакта до результата', 'steps' => []]],
                ['type' => 'PartnershipCTA',     'defaults' => ['title' => 'Присоединяйтесь к нам', 'subtitle' => 'Начните работать вместе с нами уже сегодня', 'buttonText' => 'Обсудить сотрудничество', 'phone' => '']],
            ],
            '/testimonials' => [
                ['type' => 'Hero',                'defaults' => ['eyebrow' => 'Отзывы', 'title' => 'Отзывы о нас', 'subtitle' => 'Мы работаем ради таких отзывов клиентов о нашей работе']],
                ['type' => 'TestimonialsSummary', 'defaults' => ['title' => 'Нам доверяют', 'subtitle' => 'Каждый отзыв — результат работы дизайнеров, мастеров и сборщиков', 'rating' => '4.9', 'ratingCaption' => 'на основе отзывов покупателей за всё время работы', 'stats' => []]],
                ['type' => 'TestimonialsGrid',    'defaults' => ['featured' => [], 'reviews' => []]],
            ],
            '/installment' => [
                ['type' => 'InstallmentHero',         'defaults' => ['title' => 'Рассрочка без переплаты', 'subtitle' => 'Купите мебель и технику сейчас — платите частями до 12 месяцев. Быстрое одобрение, минимум документов.', 'buttonText' => 'Консультация по рассрочке']],
                ['type' => 'InstallmentPlans',        'defaults' => ['title' => 'Программы рассрочки', 'subtitle' => 'Выберите удобный срок и условия оплаты', 'plans' => []]],
                ['type' => 'InstallmentRequirements', 'defaults' => ['title' => 'Что нужно для оформления', 'subtitle' => 'Минимум документов — максимум удобства', 'items' => []]],
                ['type' => 'InstallmentSteps',        'defaults' => ['title' => 'Как оформить рассрочку', 'subtitle' => 'Простой процесс за 4 шага', 'steps' => []]],
                ['type' => 'InstallmentBanks',        'defaults' => ['title' => 'Банки-партнёры', 'subtitle' => 'Работаем с надёжными финансовыми организациями', 'banks' => []]],
                ['type' => 'InstallmentFAQ',          'defaults' => ['title' => 'Частые вопросы', 'items' => []]],
                ['type' => 'InstallmentCTA',          'defaults' => ['title' => 'Оформите рассрочку сегодня', 'subtitle' => 'Получите мебель и технику не откладывая покупку — платите комфортными частями', 'buttonText' => 'Консультация', 'phone' => '']],
            ],
            '/guarantees' => [
                ['type' => 'GuaranteesHero',  'defaults' => ['title' => 'Гарантия качества', 'subtitle' => 'Мы уверены в качестве продукции наших партнёров. Вся продукция имеет расширенную гарантию на материалы и работу мастеров.']],
                ['type' => 'GuaranteeTerms',  'defaults' => ['title' => 'Сроки гарантии', 'subtitle' => 'Официальная гарантия от производителей на все категории', 'items' => []]],
                ['type' => 'WhatsCovered',    'defaults' => ['title' => 'Что покрывает гарантия', 'description' => 'Наша гарантия распространяется на производственные дефекты материалов и качество сборки.', 'items' => [], 'imageUrl' => '']],
                ['type' => 'HowToApply',      'defaults' => ['title' => 'Как обратиться по гарантии', 'subtitle' => 'Простой процесс решения гарантийных вопросов', 'steps' => []]],
                ['type' => 'GuaranteesCTA',   'defaults' => ['title' => 'Гарантийный случай?', 'subtitle' => 'Заполните форму или позвоните нам — решим вопрос в кратчайшие сроки', 'buttonText' => 'Оставить заявку', 'phone' => '']],
            ],
            '/yandex-direct' => [
                ['type' => 'Hero',        'defaults' => ['badge' => 'Специальное предложение', 'title' => 'Мебель на заказ по вашим размерам', 'subtitle' => 'Кухни, шкафы, гардеробные — от замера до установки за 14 дней. Рассрочка 0% и бесплатный дизайн-проект', 'primaryButton' => 'Рассчитать стоимость', 'primaryHref' => '/contact', 'secondaryButton' => 'Позвонить', 'secondaryHref' => 'tel:+70000000000']],
                ['type' => 'USP',         'defaults' => ['label' => 'Почему мы', 'title' => 'Уникальное торговое предложение', 'subtitle' => 'То, что отличает нас от конкурентов и делает сотрудничество выгодным для вас', 'items' => []]],
                ['type' => 'Advantages',  'defaults' => ['label' => 'Наши преимущества', 'title' => 'Цифры говорят за нас', 'stats' => [], 'features' => []]],
                ['type' => 'Steps',       'defaults' => ['label' => 'Как мы работаем', 'title' => '4 простых шага к вашей идеальной мебели', 'steps' => []]],
                ['type' => 'SocialProof', 'defaults' => ['label' => 'Отзывы клиентов', 'title' => 'Нам доверяют сотни клиентов', 'reviews' => []]],
                ['type' => 'Offer',       'defaults' => ['badge' => 'Ограниченное предложение', 'title' => 'Закажите сейчас — получите скидку 15%', 'subtitle' => 'Оставьте заявку до конца месяца и получите дополнительную скидку на весь заказ', 'buttonText' => 'Получить скидку', 'buttonHref' => '/contact', 'includes' => []]],
                ['type' => 'CTA',         'defaults' => ['title' => 'Готовы обсудить ваш проект?', 'subtitle' => 'Оставьте заявку — мы перезвоним в течение 15 минут и ответим на все вопросы', 'primaryButton' => 'Оставить заявку', 'primaryHref' => '/contact', 'phoneButton' => 'Позвонить нам', 'phoneHref' => 'tel:+70000000000']],
            ],
            '/consultation' => [
                ['type' => 'ConsultationHero',     'defaults' => ['badge' => 'Премиальный сервис', 'title_part1' => 'Консультация', 'title_part2' => 'дизайнера', 'description' => 'Трансформируйте свои идеи в безупречный интерьер. Получите экспертные рекомендации по стилю, эргономике и материалам от ведущих специалистов отрасли.', 'cta_text' => 'Заказать консультацию']],
                ['type' => 'ConsultationFeatures', 'defaults' => ['badge' => 'Что вы получите', 'title' => 'Комплексный подход к вашему интерьеру']],
                ['type' => 'ConsultationWhy',      'defaults' => ['title' => 'Почему начать с консультации?']],
                ['type' => 'ConsultationCTA',      'defaults' => ['title' => 'Готовы преобразить пространство?', 'description' => 'Запишитесь на консультацию сегодня и сделайте первый уверенный шаг к созданию интерьера вашей мечты.', 'cta_text' => 'Заказать консультацию']],
            ],
            '/design-project' => [
                ['type' => 'DesignProjectHero',     'defaults' => ['badge' => 'Проектирование полного цикла', 'title_part1' => 'Проект', 'title_part2' => 'дизайна', 'description' => 'Создаем не просто красивые картинки, а детально проработанные технические решения для безупречной реализации вашего интерьера.', 'cta_text' => 'Начать проект']],
                ['type' => 'DesignProjectFeatures', 'defaults' => ['badge' => 'Состав проекта', 'title' => 'Полный комплект документации']],
                ['type' => 'DesignProjectWhy',      'defaults' => ['title' => 'Почему нужен дизайн-проект?']],
                ['type' => 'DesignProjectCTA',      'defaults' => ['title_part1' => 'Готовы создать', 'title_part2' => 'свой идеал?', 'cta_text' => 'Заказать проект']],
            ],
            '/measurement' => [
                ['type' => 'MeasurementHero',     'defaults' => ['badge' => 'Услуга компании', 'title_part1' => 'Проектный замер', 'title_part2' => 'помещения', 'description' => 'Точные обмеры — основа качественного дизайн-проекта. Профессиональный замер с фиксацией всех коммуникаций и особенностей помещения.', 'cta_text' => 'Заказать замер']],
                ['type' => 'MeasurementFeatures', 'defaults' => ['badge' => 'Что мы фиксируем', 'title' => 'Детальный обмер помещения']],
                ['type' => 'MeasurementWhy',      'defaults' => ['title' => 'Зачем нужен профессиональный замер?']],
                ['type' => 'MeasurementCTA',      'defaults' => ['title' => 'Готовы начать с точного замера?', 'description' => 'Закажите профессиональный замер — первый шаг к идеальному интерьеру', 'cta_text' => 'Заказать замер']],
            ],
            '/furniture-project' => [
                ['type' => 'FurnitureProjectHero',     'defaults' => ['badge' => 'Услуга компании', 'title_part1' => 'Проектирование', 'title_part2' => 'мебели', 'description' => 'Индивидуальная мебель, спроектированная под ваше пространство с учётом ваших пожеланий. От стартового эскиза до рабочих чертежей для производства.', 'cta_text' => 'Заказать проект мебели']],
                ['type' => 'FurnitureProjectFeatures', 'defaults' => ['badge' => 'Состав проекта', 'title' => 'Полный комплект для производства']],
                ['type' => 'FurnitureProjectWhy',      'defaults' => ['title' => 'Почему мебель на заказ?']],
                ['type' => 'FurnitureProjectCTA',      'defaults' => ['title' => 'Готовы создать уникальную мебель?', 'description' => 'Закажите проект мебели и получите изделие, идеально подходящее под ваш интерьер', 'cta_text' => 'Заказать проект мебели']],
            ],
            '/assembly' => [
                ['type' => 'AssemblyHero',     'defaults' => ['badge' => 'Услуга компании', 'title_part1' => 'Сборка и', 'title_part2' => 'установка', 'description' => 'Профессиональная сборка и установка мебели любой сложности профессиональным инструментом. Быстро, чисто, аккуратно и с гарантией качества.', 'cta_text' => 'Заказать сборку']],
                ['type' => 'AssemblyFeatures', 'defaults' => ['badge' => 'Наши услуги', 'title' => 'Полный комплекс работ']],
                ['type' => 'AssemblyWhy',      'defaults' => ['title' => 'Почему выбирают нас?']],
                ['type' => 'AssemblyCTA',      'defaults' => ['title' => 'Нужна сборка мебели?', 'description' => 'Оставьте заявку и мы соберём вашу мебель быстро и качественно', 'cta_text' => 'Заказать сборку']],
            ],
            '/mebel' => [
                ['type' => 'MebelSidebar',    'defaults' => [
                    'categories' => [
                        ['value' => 'Кухни',          'slug' => 'kitchens'],
                        ['value' => 'Шкафы',          'slug' => 'wardrobes'],
                        ['value' => 'Гардеробные',    'slug' => 'dressing-rooms'],
                        ['value' => 'Прихожие',       'slug' => 'hallways'],
                        ['value' => 'Детская мебель', 'slug' => 'kids-furniture']
                    ]
                ]],
                ['type' => 'MebelHero',      'defaults' => [
                    'title' => 'Мебель на заказ',
                    'description' => 'Создаём уникальную корпусную мебель по вашим размерам и дизайну. Индивидуальный подход к каждому проекту.',
                    'primaryButton' => 'Ваш проект',
                    'secondaryButton' => 'Бесплатный замер',
                    'bgImage' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/furniture_hero.png'
                ]],
                ['type' => 'MebelBenefits',  'defaults' => [
                    'title' => 'Почему выбирают нас',
                    'items' => [
                        ['title' => 'Гарантия качества', 'desc' => 'Используются только сертифицированные материалы от надёжных производителей', 'icon' => 'shield'],
                        ['title' => 'Точные сроки', 'desc' => 'Соблюдаем оговорённые сроки изготовления, доставки и сборки мебели', 'icon' => 'clock'],
                        ['title' => 'Индивидуальный дизайн', 'desc' => 'Разрабатываем проект под ваши размеры и пожелания', 'icon' => 'design']
                    ]
                ]],
                ['type' => 'MebelSolutions', 'defaults' => [
                    'title' => 'Популярные решения',
                    'items' => [
                        ['title' => 'Кухонные гарнитуры', 'desc' => 'От классики до современного минимализма', 'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/custom_kitchens.png'],
                        ['title' => 'Шкафы', 'desc' => 'Максимум функциональности и стиля', 'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/custom_wardrobes.png']
                    ]
                ]],
                ['type' => 'MebelProcess',   'defaults' => [
                    'title' => 'Как мы работаем',
                    'steps' => [
                        ['title' => 'Консультация', 'desc' => 'Обсуждаем ваши пожелания'],
                        ['title' => 'Замер', 'desc' => 'Бесплатный выезд специалиста'],
                        ['title' => 'Проект', 'desc' => '3D-визуализация и расчёт'],
                        ['title' => 'Установка', 'desc' => 'Профессиональный монтаж']
                    ]
                ]],
                ['type' => 'MebelCTA',       'defaults' => [
                    'title' => 'Готовы начать проект?',
                    'description' => 'Оставьте заявку и получите бесплатный дизайн-проект вашей будущей мебели',
                    'buttonText' => 'Получить консультацию'
                ]],
            ],
            '/mebel/{category}' => [
                ['type' => 'MebelSidebar',    'defaults' => [
                    'categories' => [] // Will be enriched
                ]],
                ['type' => 'MebelCategoryHero', 'defaults' => [
                    'title' => 'Категория',
                    'description' => 'Описание категории',
                    'buttonText' => 'Создать проект'
                ]],
                ['type' => 'MebelProjectsGrid', 'defaults' => [
                    'projects' => [] // Will be enriched
                ]],
                ['type' => 'MebelBenefits',  'defaults' => [
                    'title' => 'Почему выбирают нас',
                    'items' => [
                        ['title' => 'Гарантия качества', 'desc' => 'Используются только сертифицированные материалы', 'icon' => 'shield'],
                        ['title' => 'Точные сроки', 'desc' => 'Соблюдаем оговорённые сроки', 'icon' => 'clock'],
                        ['title' => '3D-проект бесплатно', 'desc' => 'Визуализация вашего будущего интерьера', 'icon' => 'design']
                    ]
                ]],
                ['type' => 'MebelCTA',       'defaults' => [
                    'title' => 'Не нашли подходящий вариант?',
                    'description' => 'Мы изготовим мебель по вашему индивидуальному проекту',
                    'buttonText' => 'Заказать проект'
                ]],
            ],
            '/mebel/{category}/{project}' => [
                ['type' => 'MebelSidebar',    'defaults' => []],
                ['type' => 'MebelProjectHero', 'defaults' => []],
                ['type' => 'MebelProjectDescription', 'defaults' => []],
                ['type' => 'MebelProjectSimilar', 'defaults' => []],
                ['type' => 'MebelCTA',       'defaults' => [
                    'title' => 'Хотите такую же мебель?',
                    'description' => 'Оставьте заявку и получите бесплатный расчёт стоимости с учётом ваших размеров',
                    'buttonText' => 'Создать проект и узнать цену'
                ]],
            ],
            '/stoleshnica' => [
                ['type' => 'StoleshnicaSidebar', 'defaults' => [
                    'categories' => [
                        ['title' => 'Кварц', 'slug' => 'kvarc'],
                        ['title' => 'Акриловый камень', 'slug' => 'akril'],
                        ['title' => 'ДСП / Постформинг', 'slug' => 'dsp'],
                        ['title' => 'Массив дерева', 'slug' => 'massiv'],
                        ['title' => 'Керамика', 'slug' => 'keramika']
                    ]
                ]],
                ['type' => 'StoleshnicaHero', 'defaults' => [
                    'title'           => 'Столешницы',
                    'description'     => 'Изготавливаем столешницы из искусственного камня, кварца, массива и других материалов. Точный раскрой под вашу кухню с вырезами под мойку и варочную панель.',
                    'primaryButton'   => 'Рассчитать стоимость',
                    'secondaryButton' => 'Вызвать замерщика',
                ]],
                ['type' => 'StoleshnicaMaterials', 'defaults' => [
                    'title' => 'Сравнение материалов',
                    'rows'  => [
                        ['material' => 'Кварцевый агломерат', 'price' => 'Высокая', 'strength' => 'Высокая', 'care' => 'Простой',       'strengthColor' => 'emerald', 'careColor' => 'emerald'],
                        ['material' => 'Акриловый камень',    'price' => 'Средняя', 'strength' => 'Средняя', 'care' => 'Простой',       'strengthColor' => 'amber',   'careColor' => 'emerald'],
                        ['material' => 'ДСП / Постформинг',  'price' => 'Низкая',  'strength' => 'Средняя', 'care' => 'Простой',       'strengthColor' => 'amber',   'careColor' => 'emerald'],
                        ['material' => 'Массив дерева',       'price' => 'Высокая', 'strength' => 'Низкая',  'care' => 'Требует ухода', 'strengthColor' => 'sky',     'careColor' => 'amber'],
                        ['material' => 'Керамика',            'price' => 'Высокая', 'strength' => 'Высокая', 'care' => 'Простой',       'strengthColor' => 'emerald', 'careColor' => 'emerald'],
                    ]
                ]],
                ['type' => 'StoleshnicaBenefits', 'defaults' => [
                    'title' => 'Наши преимущества',
                    'items' => [
                        ['title' => 'Точный расчёт',              'desc' => 'Замер с точностью до миллиметра для идеальной подгонки',         'icon' => 'calc',   'color' => 'amber'],
                        ['title' => 'Профессиональный монтаж',    'desc' => 'Установка с герметизацией стыков и вырезами под технику',        'icon' => 'tools',  'color' => 'sky'],
                        ['title' => 'Гарантия 2-10 лет',          'desc' => 'Гарантия на материал и работы по установке вашего изделия',      'icon' => 'shield', 'color' => 'emerald'],
                    ]
                ]],
                ['type' => 'StoleshnicaSolutions', 'defaults' => [
                    'title' => 'Популярные решения',
                    'items' => [
                        ['title' => 'Кварцевый агломерат', 'desc' => 'Прочность и элегантность натурального камня', 'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/stoleshnica_popular_kvarc.png', 'href' => '/stoleshnica/kvarc'],
                        ['title' => 'Акриловый камень',    'desc' => 'Бесшовное соединение и любые формы',          'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/stoleshnica_popular_akril.png', 'href' => '/stoleshnica/akril'],
                    ]
                ]],
                ['type' => 'StoleshnicaServices', 'defaults' => [
                    'title'    => 'Что входит в стоимость',
                    'services' => [
                        ['title' => 'Выезд замерщика',    'desc' => 'Бесплатный замер помещения'],
                        ['title' => 'Изготовление',       'desc' => 'Производство по вашим размерам'],
                        ['title' => 'Вырезы под технику', 'desc' => 'Под мойку, варочную панель, смеситель'],
                        ['title' => 'Доставка и монтаж',  'desc' => 'Профессиональная установка'],
                    ]
                ]],
                ['type' => 'StoleshnicaCTA', 'defaults' => [
                    'title'       => 'Рассчитайте стоимость столешницы',
                    'description' => 'Оставьте заявку и получите расчёт стоимости с учётом всех вырезов и монтажа',
                    'buttonText'  => 'Получить расчёт',
                ]],
            ],

            '/bytovaya-tehnika' => [
                ['type' => 'ByttehnikaSidebar', 'defaults' => [
                    'brands' => [
                        ['title' => 'Bosch',     'slug' => 'bosch'],
                        ['title' => 'Siemens',   'slug' => 'siemens'],
                        ['title' => 'Electrolux', 'slug' => 'electrolux'],
                        ['title' => 'Hansa',     'slug' => 'hansa'],
                        ['title' => 'Gorenje',   'slug' => 'gorenje'],
                    ]
                ]],
                ['type' => 'ByttehnikaHero', 'defaults' => [
                    'title'         => 'Бытовая техника',
                    'description'   => 'Встраиваемая и отдельностоящая техника от ведущих мировых производителей. Подберём оптимальное решение с учётом ваших пожеланий и бюджета.',
                    'primaryButton' => 'Подобрать технику',
                    'bgImage'       => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/appliances_hero.png',
                ]],
                ['type' => 'ByttehnikaBrands', 'defaults' => [
                    'title'  => 'Работаем с лучшими брендами',
                    'brands' => [
                        ['title' => 'Bosch',     'slug' => 'bosch'],
                        ['title' => 'Siemens',   'slug' => 'siemens'],
                        ['title' => 'Electrolux', 'slug' => 'electrolux'],
                        ['title' => 'Hansa',     'slug' => 'hansa'],
                        ['title' => 'Gorenje',   'slug' => 'gorenje'],
                        ['title' => 'Whirlpool', 'slug' => 'whirlpool'],
                    ]
                ]],
                ['type' => 'ByttehnikaBenefits', 'defaults' => [
                    'title' => 'Почему покупают у нас',
                    'items' => [
                        ['title' => 'Официальная гарантия', 'desc' => 'Вся техника с официальной гарантией производителя до 5 лет', 'icon' => 'shield', 'color' => 'emerald'],
                        ['title' => 'Выгодные цены',        'desc' => 'Прямые поставки техники от производителей без посредников',  'icon' => 'wallet', 'color' => 'sky'],
                        ['title' => 'Быстрая доставка',     'desc' => 'Доставим технику в удобное время с подъёмом на этаж',        'icon' => 'bolt',   'color' => 'amber'],
                    ]
                ]],
                ['type' => 'ByttehnikaCategories', 'defaults' => [
                    'title' => 'Популярные категории',
                    'items' => [
                        ['title' => 'Варочные панели', 'desc' => 'Индукционные, газовые, электрические',  'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/varochna.jpg'],
                        ['title' => 'Духовые шкафы',   'desc' => 'Встраиваемые с конвекцией и грилем',    'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/duhshkaf.jpg'],
                    ]
                ]],
                ['type' => 'ByttehnikaComplex', 'defaults' => [
                    'title'       => 'Комплексное решение',
                    'description' => 'Закажите кухню вместе с техникой и получите скидку до 15% на весь комплект. Наши дизайнеры подберут технику, которая идеально впишется в ваш проект.',
                    'buttonText'  => 'Заказать проект мебели',
                    'perks'       => [
                        'Единый проект кухни и техники',
                        'Скидка на комплект до 15%',
                        'Одновременная доставка и установка',
                    ]
                ]],
                ['type' => 'ByttehnikaCTA', 'defaults' => [
                    'title'       => 'Нужна помощь с выбором?',
                    'description' => 'Наши специалисты помогут подобрать технику под ваши задачи и бюджет',
                    'buttonText'  => 'Получить консультацию',
                ]],
            ],

            '/santehnika' => [
                ['type' => 'SantehnikaSidebar', 'defaults' => [
                    'brands' => [
                        ['title' => 'Blanco',   'slug' => 'blanco'],
                        ['title' => 'Grohe',    'slug' => 'grohe'],
                        ['title' => 'Hansgrohe', 'slug' => 'hansgrohe'],
                        ['title' => 'Franke',   'slug' => 'franke'],
                        ['title' => 'Omoikiri', 'slug' => 'omoikiri'],
                    ]
                ]],
                ['type' => 'SantehnikaHero', 'defaults' => [
                    'title'         => 'Сантехника',
                    'description'   => 'Кухонные мойки, смесители, измельчители и аксессуары от ведущих производителей. Подберём идеальное сочетание цвета и формы для вашей кухни.',
                    'primaryButton' => 'Подобрать комплект',
                    'bgImage'       => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/plumbing_hero.png',
                ]],
                ['type' => 'SantehnikaBrands', 'defaults' => [
                    'title'  => 'Бренды сантехники',
                    'brands' => [
                        ['title' => 'Blanco',   'slug' => 'blanco'],
                        ['title' => 'Grohe',    'slug' => 'grohe'],
                        ['title' => 'Hansgrohe', 'slug' => 'hansgrohe'],
                        ['title' => 'Franke',   'slug' => 'franke'],
                        ['title' => 'Omoikiri', 'slug' => 'omoikiri'],
                        ['title' => 'Elikor',   'slug' => 'elikor'],
                    ]
                ]],
                ['type' => 'SantehnikaSinkTypes', 'defaults' => [
                    'title' => 'Типы кухонных моек',
                    'items' => [
                        ['title' => 'Нержавеющая сталь',    'desc' => 'Классика для любой кухни. Прочные, гигиеничные, доступные по цене',                 'icon' => 'cube',     'color' => 'slate'],
                        ['title' => 'Гранитные композитные', 'desc' => 'Стильный внешний вид, устойчивость к царапинам и высоким температурам',            'icon' => 'sparkles', 'color' => 'amber'],
                        ['title' => 'Керамические',          'desc' => 'Элегантность и долговечность. Идеально для классических интерьеров',                'icon' => 'palette',  'color' => 'sky'],
                    ]
                ]],
                ['type' => 'SantehnikaCategories', 'defaults' => [
                    'title' => 'Популярные категории',
                    'items' => [
                        ['title' => 'Кухонные мойки', 'desc' => 'Врезные, накладные, интегрированные',         'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/kitchen_sink.png'],
                        ['title' => 'Смесители',       'desc' => 'С выдвижным изливом, сенсорные, классические', 'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/kitchen_faucet.png'],
                    ]
                ]],
                ['type' => 'SantehnikaBenefits', 'defaults' => [
                    'title' => 'Почему выбирают нас',
                    'items' => [
                        ['title' => 'Оригинальная продукция',  'desc' => 'Только сертифицированная сантехника от официальных дистрибьюторов', 'icon' => 'shield', 'color' => 'emerald'],
                        ['title' => 'Профессиональный монтаж', 'desc' => 'Установка с подключением и проверкой на герметичность',             'icon' => 'tools',  'color' => 'sky'],
                        ['title' => 'Выгодные комплекты',      'desc' => 'Скидки при покупке мойки со смесителем и аксессуарами',             'icon' => 'coin',   'color' => 'amber'],
                    ]
                ]],
                ['type' => 'SantehnikaComplex', 'defaults' => [
                    'title'       => 'Комплект для кухни',
                    'description' => 'Закажите мойку вместе со смесителем, измельчителем и диспенсером — получите скидку до 20% на комплект.',
                    'buttonText'  => 'Собрать комплект',
                    'perks'       => [
                        'Мойка + смеситель в едином стиле',
                        'Измельчитель пищевых отходов',
                        'Диспенсер для моющего средства',
                    ]
                ]],
                ['type' => 'SantehnikaCTA', 'defaults' => [
                    'title'       => 'Нужна помощь с выбором?',
                    'description' => 'Наши специалисты помогут подобрать сантехнику под вашу кухню и бюджет',
                    'buttonText'  => 'Получить консультацию',
                ]],
            ],

            '/furnitura' => [
                ['type' => 'FurnituraSidebar', 'defaults' => [
                    'shops' => []
                ]],
                ['type' => 'FurnituraHero', 'defaults' => [
                    'title'         => 'Фурнитура',
                    'description'   => 'Каталог интернет-магазинов и поставщиков мебельной фурнитуры. Петли, направляющие, подъёмники и системы хранения от проверенных поставщиков.',
                    'primaryButton' => 'Подобрать фурнитуру',
                    'bgImage'       => 'https://storage.yandexcloud.net/leget-main/templates/promo-1/furniture_fittings_hero.png',
                ]],
                ['type' => 'FurnituraShops', 'defaults' => [
                    'title' => 'Магазины и поставщики',
                    'shops' => []
                ]],
                ['type' => 'FurnituraCTA', 'defaults' => [
                    'title'       => 'Подберём фурнитуру под ваш проект',
                    'description' => 'Поможем выбрать оптимальное решение с учётом бюджета и требований к мебели',
                    'buttonText'  => 'Получить консультацию',
                ]],
            ],

            '/plitka' => [
                ['type' => 'PliitkaSidebar', 'defaults' => [
                    'brands' => [
                        ['title' => 'Italon',   'slug' => 'italon'],
                        ['title' => 'Kerama Marazzi', 'slug' => 'kerama-marazzi'],
                        ['title' => 'Atlas Concorde', 'slug' => 'atlas-concorde'],
                        ['title' => 'Estima',   'slug' => 'estima'],
                    ]
                ]],
                ['type' => 'PliitkaHero', 'defaults' => [
                    'title'         => 'Плитка',
                    'description'   => 'Керамическая плитка, керамогранит и мозаика от ведущих мировых производителей. Подберём оптимальное решение для любого интерьера.',
                    'primaryButton' => 'Подобрать плитку',
                    'bgImage'       => '',
                ]],
                ['type' => 'PliitkaBrands', 'defaults' => [
                    'title'  => 'Работаем с лучшими брендами',
                    'brands' => [
                        ['title' => 'Italon',   'slug' => 'italon'],
                        ['title' => 'Kerama Marazzi', 'slug' => 'kerama-marazzi'],
                        ['title' => 'Atlas Concorde', 'slug' => 'atlas-concorde'],
                        ['title' => 'Estima',   'slug' => 'estima'],
                    ]
                ]],
                ['type' => 'PliitkaBenefits', 'defaults' => [
                    'title' => 'Почему выбирают нас',
                    'items' => [
                        ['title' => 'Сертифицированная продукция', 'desc' => 'Вся плитка сертифицирована и соответствует стандартам качества', 'icon' => 'shield', 'color' => 'emerald'],
                        ['title' => 'Широкий ассортимент',         'desc' => 'Более 1000 коллекций плитки различных стилей и форматов', 'icon' => 'grid', 'color' => 'sky'],
                        ['title' => 'Профессиональный подбор',      'desc' => 'Поможем подобрать плитку, подходящую по стилю и бюджету вашего проекта', 'icon' => 'bolt', 'color' => 'amber'],
                    ]
                ]],
                ['type' => 'PliitkaCategories', 'defaults' => [
                    'title' => 'Популярные категории',
                    'items' => [
                        ['title' => 'Керамогранит',        'desc' => 'Прочный и долговечный материал для пола и стен', 'gradient' => 'from-amber-50 to-orange-50'],
                        ['title' => 'Керамическая плитка', 'desc' => 'Классическое решение для ванной и кухни',        'gradient' => 'from-sky-50 to-blue-50'],
                    ]
                ]],
                ['type' => 'PliitkaComplex', 'defaults' => [
                    'title'       => 'Комплексное решение',
                    'description' => 'Закажите плитку вместе с дизайн-проектом и получите скидку на весь комплект. Наши дизайнеры подберут плитку, которая идеально впишется в ваш интерьер.',
                    'buttonText'  => 'Заказать дизайн-проект',
                    'perks'       => [
                        'Подбор плитки под дизайн-проект',
                        'Расчёт количества материала',
                        'Доставка и укладка под ключ',
                    ]
                ]],
                ['type' => 'PliitkaCTA', 'defaults' => [
                    'title'       => 'Нужна помощь с выбором?',
                    'description' => 'Наши специалисты помогут подобрать плитку под ваши задачи и бюджет',
                    'buttonText'  => 'Получить консультацию',
                ]],
            ],

        ],
    ],

    2 => [
        'name'  => 'Promo-2',
        'pages' => [
            '/' => [
                ['type' => 'Hero',       'defaults' => ['label' => 'Белорусская фабрика мебели', 'title' => 'Мебель европейского качества по доступной цене', 'description' => 'Создаём кухонные гарнитуры, шкафы и гардеробы, которые сочетают безупречный дизайн, премиальные материалы и выверенную функциональность изделий', 'ctaPrimary' => 'Дизайнер на дом', 'ctaPrimaryLink' => '/contact', 'ctaSecondary' => 'Промокод на 10% скидку', 'ctaSecondaryLink' => '/actions']],
                ['type' => 'Styles',     'defaults' => ['label' => 'Коллекции', 'heading' => 'Найдите свой стиль', 'allLinkText' => 'Все стили', 'allLink' => '/styles']],
                ['type' => 'Advantages', 'defaults' => ['label' => 'Преимущества', 'heading' => 'Почему выбирают нас', 'description' => 'Мы объединяем многолетний опыт, передовые технологии и внимание к деталям для создания мебели, которая превосходит ожидания.']],
                ['type' => 'Details',    'defaults' => ['label' => 'Мебельная фабрика', 'heading' => 'Мебель для жизни', 'description' => 'Мебель — это сочетание безупречного дизайна, функциональности и долговечности. Мы используем только качественные материалы и фурнитуру от ведущих мировых производителей.']],
                ['type' => 'CTA',        'defaults' => ['label' => 'Начните сейчас', 'heading' => 'Создадим кухню вашей мечты', 'description' => 'Запишитесь на бесплатную консультацию. Наш дизайнер поможет подобрать идеальное решение для вашего пространства.', 'buttonText' => 'Бесплатная консультация', 'buttonLink' => '/contact']],
            ],
            '/about' => [
                ['type' => 'Hero',          'defaults' => ['label' => 'О фабрике', 'title' => 'От нашей фабрики для вашей семьи', 'description' => 'Наша фабрика располагает самой крупной сетью мебельных салонов. Предлагаем отличный сервис и доступные цены на мебель премиального качества.', 'ctaPrimary' => 'Найти ближайший салон', 'ctaPrimaryLink' => '/showrooms']],
                ['type' => 'LeaderSection', 'defaults' => ['quote' => 'Мы вкладываем весь свой опыт и душу в создание мебели', 'name' => 'Зуховицкий О.В.', 'role' => 'Руководитель фабрики ЗОВ', 'image' => 'https://storage.yandexcloud.net/leget-main/templates/promo-2/zovdir.png']],
                ['type' => 'Mission',       'defaults' => ['label' => 'Наша миссия', 'heading' => 'Мы создаём мебель, которая дарит радость']],
				['type' => 'Factory',       'defaults' => [
					'label' => 'Производство',
					'heading' => 'Наша фабрика',
					'description' => '25 000 м² современного производства, оснащённого передовым европейским оборудованием',
					'factoryImages' => [
						'https://storage.yandexcloud.net/zovtop/foto/fabr-1jhbnikjnmim.jpg',
						'https://storage.yandexcloud.net/zovtop/foto/fabr-2jfnvkjfdvijkmf.jpg',
						'https://storage.yandexcloud.net/zovtop/foto/fabr-3kjvndfnvjhdgnvjhd.jpg',
						'https://storage.yandexcloud.net/zovtop/foto/fabr-4dlkfvmdfmvjkfd.jpg',
						'https://storage.yandexcloud.net/zovtop/foto/fabr-5kjfndvjkdfgknkgj.jpg',
					],
				]],
                ['type' => 'Video',         'defaults' => ['src' => 'https://storage.yandexcloud.net/leget-main/templates/promo-2/zov.mp4']],
                ['type' => 'Principles',    'defaults' => ['label' => 'Наши ценности', 'heading' => 'Принципы компании']],
                ['type' => 'AboutCTA',      'defaults' => ['eyebrow' => 'Бесплатная услуга', 'heading' => 'Закажите дизайн-проект', 'description' => 'Запишитесь на бесплатную консультацию. Наш дизайнер поможет подобрать идеальное решение.', 'ctaText' => 'Заказать дизайн-проект', 'ctaLink' => '/contact']],
            ],
            '/news' => [
                ['type' => 'Hero',     'defaults' => ['label' => 'Мебельная фабрика', 'heading' => 'Новости', 'description' => 'События, обновления и достижения фабрики']],
                ['type' => 'NewsList', 'defaults' => []],
                ['type' => 'NewsCTA',  'defaults' => ['eyebrow' => 'Хотите узнавать первыми?', 'heading' => 'Посетите наши салоны', 'description' => 'Наши дизайнеры всегда в курсе последних новинок и акций.', 'ctaText' => 'Найти ближайший салон', 'ctaLink' => '/showrooms']],
            ],
            '/styles' => [
                ['type' => 'Hero',       'defaults' => ['label' => 'Коллекции', 'heading' => 'Стили кухонь', 'description' => 'Откройте для себя наше портфолио. От строгой классики до минимализма — найдите идеальное решение, отражающее ваш индивидуальный вкус.']],
                ['type' => 'StylesGrid', 'defaults' => []],
                ['type' => 'StylesCTA',  'defaults' => ['heading' => 'Поможем с выбором', 'description' => 'Запишитесь на встречу с нашим дизайнером. Мы подберем идеальный стиль, материалы и фурнитуру, учитывая архитектуру вашего пространства.', 'ctaText' => 'Записаться в салон', 'ctaLink' => '/contact']],
            ],
            '/facades' => [
                ['type' => 'Hero',           'defaults' => ['label' => 'Материалы', 'title' => 'Фасад это лицо вашей мебели', 'description' => 'Фасады задают характер вашего домашнего интерьера. От натурального массива до технологичного акрила: мы предлагаем материалы, которые сочетают в себе безупречную эстетику, функциональность и долговечность.']],
                ['type' => 'FacadesCatalog', 'defaults' => []],
                ['type' => 'FacadesCTA',     'defaults' => ['eyebrow' => 'Образцы', 'heading' => 'Посмотрите вживую', 'description' => 'Посетите салон фабрики, чтобы прикоснуться к образцам.', 'ctaText' => 'Запись в салон', 'ctaLink' => '/contact']],
            ],
            '/furniture' => [
                ['type' => 'Hero',           'defaults' => ['label' => 'Функциональность', 'title' => 'Безупречное движение', 'description' => 'Мебель премиум-класса требует фурнитуры соответствующего уровня. Мы предлагаем решения от ведущих европейских брендов, которые сделают каждое движение фасада или ящика плавным и комфортным.']],
                ['type' => 'FurnitureIntro', 'defaults' => ['eyebrow' => 'Качество в деталях', 'heading' => 'Надежность, которую вы чувствуете каждый день', 'description' => 'Фурнитура — это невидимое сердце любой мебели. От нее зависит, насколько плавно будут открываться дверцы, как тихо будут закрываться ящики и сколько лет мебель прослужит без единого скрипа.']],
                ['type' => 'BrandsSection',  'defaults' => []],
                ['type' => 'FurnitureCTA',   'defaults' => ['eyebrow' => 'Консультация', 'heading' => 'Поможем выбрать лучшую систему для вашей мебели', 'ctaText' => 'Записаться в салон', 'ctaLink' => '/contact']],
            ],
            '/contact' => [
                ['type' => 'ContactForm', 'defaults' => ['title' => 'Контакты', 'email' => 'info@example.com']],
                ['type' => 'Map',         'defaults' => ['address' => 'Москва, ул. Примерная, 1']],
            ],
            '/actions' => [
                ['type' => 'Hero',          'defaults' => ['label' => 'Специальные предложения', 'title' => 'Скидки & акции', 'description' => '', 'ctaText' => 'Расчёт проекта за час', 'ctaLink' => '/contact', 'discountBadge' => '−30%', 'statCount' => '6+', 'statDiscount' => '−30%']],
                ['type' => 'ActionsTimer',  'defaults' => ['badge' => 'Главные акции сезона', 'heading' => 'Сезонные акции', 'description' => 'Успейте оформить заказ до окончания акции', 'ctaText' => 'Консультация по акциям', 'ctaLink' => '/contact', 'deadline' => '2026-12-31T00:00:00']],
                ['type' => 'ActionsCards',  'defaults' => ['label' => 'Текущие предложения', 'heading' => 'Все акции', 'consultLink' => '/contact']],
                ['type' => 'ActionsBanner', 'defaults' => ['eyebrow' => 'Специальное предложение', 'title' => 'Кухня мечты −30%', 'description' => '', 'ctaText' => 'Консультация по акции', 'ctaLink' => '/contact']],
                ['type' => 'ActionsSteps',  'defaults' => ['label' => 'Просто', 'heading' => 'Как получить скидку', 'description' => 'Воспользоваться акцией легко — всего 3 шага до вашей новой мебели по выгодной цене']],
                ['type' => 'ActionsCTA',    'defaults' => ['eyebrow' => 'Не упустите момент', 'title' => 'Запишитесь на бесплатную консультацию', 'description' => 'Наш дизайнер поможет выбрать подходящую акцию, разработает проект и рассчитает точную стоимость с учётом скидок', 'ctaText' => 'Записаться на консультацию', 'ctaLink' => '/contact', 'phone' => '']],
            ],
            '/kitchens' => [
                ['type' => 'Hero',            'defaults' => ['title' => 'Создание вашей идеальной кухни', 'description' => 'От детального проектирования до бережной сборки — каждый этап контролируется нашими специалистами', 'ctaText' => 'Спроектировать кухню', 'ctaLink' => '/contact']],
                ['type' => 'KitchensGallery', 'defaults' => ['label' => 'Галерея', 'heading' => 'Наши гарнитуры', 'description' => 'Ознакомьтесь с вариантами решений для вашей кухни.']],
				['type' => 'ProductionCycle', 'defaults' => [
					'label' => 'Этапы',
					'heading' => 'Производственный цикл',
					'description' => 'Отточенный годами процесс создания премиальной мебели.',
					'designImage' => 'https://storage.yandexcloud.net/zovtop/foto/technoljergbmeogkmbktgg.jpg',
					'productionImage' => 'https://storage.yandexcloud.net/zovtop/foto/proizvodlkfegbmrgbm.jpg',
					'assemblyImage' => '',
				]],
                ['type' => 'KitchenStyles',   'defaults' => ['heading' => 'Варианты стилистических решений', 'description' => 'Мы адаптируем индивидуальный проект под любой стиль.']],
                ['type' => 'KitchensCTA',     'defaults' => ['heading' => 'Хотите заказать кухню?', 'description' => 'Запишитесь в наши салоны для бесплатной консультации с дизайнером.', 'ctaText' => 'Записаться в салон', 'ctaLink' => '/contact']],
            ],
            '/wardrobes' => [
                ['type' => 'Hero',             'defaults' => ['label' => 'Системы хранения', 'title' => 'Идеальный порядок в каждой детали', 'description' => 'Мы создаем уникальные встроенные и корпусные шкафы, которые становятся органичным продолжением вашей квартиры.', 'ctaText' => 'Спроектировать шкаф', 'ctaLink' => '/contact']],
                ['type' => 'WardrobesGallery', 'defaults' => ['label' => 'Галерея', 'heading' => 'Наши шкафы и гардеробные']],
                ['type' => 'WardrobeFeatures', 'defaults' => ['heading' => 'Безупречное качество от замера до установки']],
                ['type' => 'WardrobeTypes',    'defaults' => ['label' => 'Варианты решений', 'heading' => 'Виды систем']],
                ['type' => 'WardrobesCTA',     'defaults' => ['heading' => 'Закажите расчет стоимости', 'ctaText' => 'Запись в салон', 'ctaLink' => '/contact']],
            ],
            '/showrooms' => [
                ['type' => 'Hero',         'defaults' => ['label' => 'Где купить', 'title' => 'Наши салоны', 'description' => 'Посетите один из наших фирменных салонов. Оцените качество материалов вживую.', 'ctaText' => 'Посмотреть карту', 'ctaLink' => '#network-section']],
                ['type' => 'ShowroomsMap', 'defaults' => []],
                ['type' => 'ShowroomsCTA', 'defaults' => ['heading' => 'Нет времени на поездку в салон?', 'description' => 'Закажите выезд дизайнера на дом абсолютно бесплатно.', 'ctaText' => 'Вызвать дизайнера', 'ctaLink' => '/contact']],
            ],
            '/careers' => [
                ['type' => 'Hero',             'defaults' => ['label' => 'Карьера', 'title' => 'Строй карьеру вместе с нами', 'description' => 'Фабрика — это более 700 специалистов, которые создают премиальную мебель. Присоединяйтесь к команде профессионалов.']],
                ['type' => 'CareersPerks',     'defaults' => ['label' => 'Почему мы', 'heading' => 'Условия работы']],
                ['type' => 'CareersVacancies', 'defaults' => ['label' => 'Открытые позиции', 'heading' => 'Вакансии']],
                ['type' => 'CareersForm',      'defaults' => ['label' => 'Открытое резюме', 'heading' => 'Отправьте ваше резюме', 'description' => 'Отправьте открытое резюме — мы сохраняем его в базе и свяжемся, когда появится подходящая позиция.', 'email' => 'info@zov.top', 'phone' => '+7 915 400-00-20']],
            ],
            '/designers' => [
                ['type' => 'Hero',              'defaults' => ['label' => 'Партнёрская программа', 'title' => 'Для дизайнеров и студий', 'description' => 'Станьте партнёром фабрики — и предлагайте клиентам премиальную мебель с эксклюзивными условиями.']],
                ['type' => 'DesignersBenefits', 'defaults' => ['label' => 'Что вы получаете', 'heading' => 'Преимущества партнёрства']],
                ['type' => 'DesignersTracks',   'defaults' => ['label' => 'Выберите формат', 'heading' => 'Программы сотрудничества']],
                ['type' => 'DesignersSteps',    'defaults' => ['label' => 'Просто и быстро', 'heading' => 'Как стать партнёром']],
                ['type' => 'DesignersForm',     'defaults' => ['label' => 'Начните сегодня', 'heading' => 'Оставьте заявку — мы позвоним первыми', 'description' => 'Расскажите о себе, и мы предложим наиболее подходящие условия сотрудничества.', 'email' => 'info@zov.top', 'phone' => '+7 915 400-00-20']],
            ],
            '/yandex-direct' => [
                ['type' => 'Hero',        'defaults' => ['badge' => 'Специальное предложение', 'title' => 'Мебель на заказ по вашим размерам', 'subtitle' => 'Кухни, шкафы, гардеробные — от замера до установки за 14 дней. Рассрочка 0% и бесплатный дизайн-проект', 'primaryButton' => 'Рассчитать стоимость', 'primaryHref' => '/contact', 'secondaryButton' => 'Позвонить', 'secondaryHref' => 'tel:+70000000000']],
                ['type' => 'USP',         'defaults' => ['label' => 'Почему мы', 'title' => 'Уникальное торговое предложение', 'subtitle' => 'То, что отличает нас от конкурентов и делает сотрудничество выгодным для вас', 'items' => []]],
                ['type' => 'Advantages',  'defaults' => ['label' => 'Наши преимущества', 'title' => 'Цифры говорят за нас', 'stats' => [], 'features' => []]],
                ['type' => 'Steps',       'defaults' => ['label' => 'Как мы работаем', 'title' => '4 простых шага к вашей идеальной мебели', 'steps' => []]],
                ['type' => 'SocialProof', 'defaults' => ['label' => 'Отзывы клиентов', 'title' => 'Нам доверяют сотни клиентов', 'reviews' => []]],
                ['type' => 'Offer',       'defaults' => ['badge' => 'Ограниченное предложение', 'title' => 'Закажите сейчас — получите скидку 15%', 'subtitle' => 'Оставьте заявку до конца месяца и получите дополнительную скидку на весь заказ', 'buttonText' => 'Получить скидку', 'buttonHref' => '/contact', 'includes' => []]],
                ['type' => 'CTA',         'defaults' => ['eyebrow' => 'Готовы начать?', 'title' => 'Готовы обсудить ваш проект?', 'subtitle' => 'Оставьте заявку — мы перезвоним в течение 15 минут и ответим на все вопросы', 'primaryButton' => 'Оставить заявку', 'primaryHref' => '/contact', 'phoneButton' => 'Позвонить нам', 'phoneHref' => 'tel:+70000000000']],
            ],
        ],
    ],

    3 => [
        'name'  => 'Promo-3',
        'pages' => [
            '/' => [
                ['type' => 'Hero',        'defaults' => ['tag' => 'Коллекция 2026', 'title' => 'Искусство плитки в каждой детали', 'subtitle' => 'Качественная плитка из разных материалов от ведущих мировых производителей.', 'ctaPrimary' => 'Промокод на скидку 10%', 'ctaPrimaryLink' => '/contacts', 'ctaSecondary' => 'Курьер с образцом', 'ctaSecondaryLink' => '/contacts']],
                ['type' => 'Categories',  'defaults' => ['label' => 'Категории', 'heading' => 'Подберите идеальную плитку', 'allLinkText' => 'Весь каталог', 'allLink' => '/collections']],
                ['type' => 'Banner',      'defaults' => ['label' => 'Для дизайнеров', 'heading' => 'Визуализируйте пространство до покупки', 'description' => 'Наши специалисты создадут 3D-раскладку плитки в вашем интерьере.', 'ctaText' => 'Заказать визуализацию', 'ctaLink' => '/contacts', 'floatingTitle' => '3D-визуализация', 'floatingSubtitle' => 'Бесплатно для заказов']],
                ['type' => 'Collections', 'defaults' => ['label' => 'Популярное', 'heading' => 'Бестселлеры коллекций', 'description' => 'Самые востребованные коллекции плитки, выбранные нашими клиентами и профессиональными дизайнерами']],
                ['type' => 'Advantages',  'defaults' => ['label' => 'Почему мы', 'heading' => 'Преимущества работы с нами']],
                ['type' => 'CTA',         'defaults' => ['heading' => 'Готовы начать проект?', 'description' => 'Свяжитесь с нами для бесплатной консультации. Наши специалисты помогут подобрать идеальную плитку для вашего интерьера.', 'ctaPrimary' => 'Позвонить нам', 'ctaPrimaryLink' => '/contacts', 'ctaSecondary' => 'Оставить заявку', 'ctaSecondaryLink' => '/contacts']],
            ],
            '/about' => [
                ['type' => 'Hero',     'defaults' => ['tag' => 'Наша история', 'title' => 'О компании', 'titleAccent' => 'PLITKA', 'description' => 'Мы помогаем создавать красивые интерьеры уже 15 лет. Прямые поставки от лучших мировых производителей, честные цены и экспертиза на каждом шагу.']],
                ['type' => 'Mission',  'defaults' => ['label' => 'Наша миссия', 'heading' => 'Красота в каждом квадратном метре', 'quote' => 'Плитка — это не просто материал. Это основа атмосферы, которую вы создаёте в своём доме.', 'quoteName' => 'Александр Петров', 'quoteRole' => 'Основатель компании', 'quoteInitials' => 'АП']],
                ['type' => 'Values',   'defaults' => ['label' => 'Наши принципы', 'heading' => 'На чём держится компания']],
                ['type' => 'Timeline', 'defaults' => ['label' => 'Хронология', 'heading' => 'Как мы росли']],
                ['type' => 'Team',     'defaults' => ['label' => 'Люди', 'heading' => 'Команда', 'description' => 'За каждым заказом стоит команда профессионалов, которые искренне любят своё дело.']],
                ['type' => 'Brands',   'defaults' => ['label' => 'Наши партнёры', 'heading' => 'Бренды, которым мы доверяем', 'description' => 'Прямые контракты с ведущими производителями — гарантия оригинальности и лучших цен.']],
                ['type' => 'CTA',      'defaults' => ['heading' => 'Готовы начать вместе?', 'description' => 'Свяжитесь с нами — поможем подобрать идеальную плитку, рассчитаем количество и сделаем 3D-визуализацию бесплатно.', 'ctaPrimary' => 'Смотреть каталог', 'ctaPrimaryLink' => '/collections', 'ctaSecondary' => 'Связаться с нами', 'ctaSecondaryLink' => '/contacts']],
            ],
            '/brands' => [
                ['type' => 'Hero',       'defaults' => ['tag' => 'Наши партнёры', 'title' => 'Бренды', 'description' => 'Прямые поставки от ведущих производителей плитки из России, Италии, Испании и всей Европы.']],
                ['type' => 'BrandsList', 'defaults' => []],
                ['type' => 'CTA',        'defaults' => ['heading' => 'Не нашли нужный бренд?', 'description' => 'Свяжитесь с нами — мы работаем с широким кругом производителей и поможем найти нужную коллекцию под ваш проект.', 'ctaPrimary' => 'Связаться с нами', 'ctaPrimaryLink' => '/contacts', 'ctaSecondary' => 'Смотреть каталог', 'ctaSecondaryLink' => '/collections']],
            ],
            '/collections' => [
                ['type' => 'Hero',            'defaults' => ['tag' => 'Каталог 2026', 'title' => 'Коллекции плитки', 'description' => 'Откройте для себя уникальные коллекции от ведущих мировых производителей.']],
                ['type' => 'CollectionsList', 'defaults' => []],
                ['type' => 'CTA',             'defaults' => ['heading' => 'Не нашли нужную коллекцию?', 'description' => 'Свяжитесь с нами — мы поможем подобрать идеальную плитку под ваш проект и бюджет.', 'ctaPrimary' => 'Связаться с нами', 'ctaPrimaryLink' => '/contacts', 'ctaSecondary' => 'Все бренды', 'ctaSecondaryLink' => '/brands']],
            ],
            '/contacts' => [
                ['type' => 'Hero',    'defaults' => ['tag' => 'Связаться с нами', 'title' => 'Контакты', 'description' => 'Посетите наши партнёрские салоны, закажите образцы с доставкой или оставьте заявку — и получите скидку 10%.']],
                ['type' => 'Salons',  'defaults' => ['label' => 'Партнёрские салоны', 'heading' => 'Посмотрите образцы вживую', 'description' => 'В наших партнёрских салонах представлены сотни коллекций.']],
                ['type' => 'Courier', 'defaults' => ['label' => 'Курьер с образцами', 'heading' => 'Образцы привезём к вам', 'description' => 'Не можете приехать в салон? Наш курьер доставит образцы понравившихся коллекций прямо к вам домой или в офис. Бесплатно, в удобное время.', 'ctaText' => 'Заказать доставку образцов', 'ctaLink' => 'tel:+78001234567']],
                ['type' => 'Form',    'defaults' => ['label' => 'Специальное предложение', 'heading' => 'Скидка 10%', 'description' => 'Оставьте имя и номер телефона — мы пришлём промокод. Без спама, только актуальные предложения.', 'buttonText' => 'Получить промокод на 10%']],
            ],
            '/materials' => [
                ['type' => 'Hero',       'defaults' => ['tag' => 'Ассортимент', 'title' => 'Материалы', 'description' => 'Полный ассортимент отделочных материалов для вашего проекта: от классической керамики до эксклюзивного натурального камня.']],
                ['type' => 'Categories', 'defaults' => ['heading' => 'Категории материалов', 'description' => 'Все виды плитки и облицовочных материалов в одном месте.']],
                ['type' => 'HowWeWork', 'defaults' => ['heading' => 'Как мы работаем', 'description' => 'Простой процесс от выбора до укладки']],
                ['type' => 'CTA',        'defaults' => ['heading' => 'Нужна помощь с выбором?', 'description' => 'Наши специалисты помогут подобрать материал, рассчитать количество и подготовить смету для вашего проекта.', 'ctaPrimary' => 'Получить консультацию', 'ctaPrimaryLink' => '/contacts', 'ctaSecondary' => 'Смотреть коллекции', 'ctaSecondaryLink' => '/collections']],
            ],
            '/services' => [
                ['type' => 'Hero',         'defaults' => ['tag' => 'Для вашего проекта', 'title' => 'Услуги', 'description' => 'Берём на себя все этапы — от доставки материалов до реализации дизайн-проекта «под ключ». Работаем профессионально и в срок.']],
                ['type' => 'ServicesGrid', 'defaults' => []],
                ['type' => 'CTA',          'defaults' => ['heading' => 'Готовы обсудить ваш проект?', 'description' => 'Свяжитесь с нами — бесплатно проконсультируем, рассчитаем стоимость и подберём оптимальное решение под ваши задачи.', 'ctaPrimary' => 'Связаться с нами', 'ctaPrimaryLink' => '/contacts']],
            ],
            '/vacancies' => [
                ['type' => 'Hero',          'defaults' => ['tag' => 'Команда', 'title' => 'Вакансии', 'description' => 'Мы строим команду профессионалов, которым важно качество. Присоединяйтесь — вместе создаём пространства, которыми гордятся.']],
                ['type' => 'VacanciesList', 'defaults' => []],
                ['type' => 'Form',          'defaults' => ['label' => 'Отклик на вакансию', 'heading' => 'Напишите нам', 'description' => 'Укажите имя и телефон — мы свяжемся с вами, обсудим детали и договоримся о встрече.', 'buttonText' => 'Отправить отклик']],
            ],
            '/yandex-direct' => [
                ['type' => 'Hero',        'defaults' => ['badge' => 'Специальное предложение', 'title' => 'Мебель на заказ по вашим размерам', 'subtitle' => 'Кухни, шкафы, гардеробные — от замера до установки за 14 дней. Рассрочка 0% и бесплатный дизайн-проект', 'primaryButton' => 'Рассчитать стоимость', 'primaryHref' => '/contacts', 'secondaryButton' => 'Позвонить', 'secondaryHref' => 'tel:+70000000000']],
                ['type' => 'USP',         'defaults' => ['label' => 'Почему мы', 'title' => 'Уникальное торговое предложение', 'subtitle' => 'То, что отличает нас от конкурентов и делает сотрудничество выгодным для вас', 'items' => []]],
                ['type' => 'Advantages',  'defaults' => ['label' => 'Наши преимущества', 'title' => 'Цифры говорят за нас', 'stats' => [], 'features' => []]],
                ['type' => 'Steps',       'defaults' => ['label' => 'Как мы работаем', 'title' => '4 простых шага к вашей идеальной мебели', 'steps' => []]],
                ['type' => 'SocialProof', 'defaults' => ['label' => 'Отзывы клиентов', 'title' => 'Нам доверяют сотни клиентов', 'reviews' => []]],
                ['type' => 'Offer',       'defaults' => ['badge' => 'Ограниченное предложение', 'title' => 'Закажите сейчас — получите скидку 15%', 'subtitle' => 'Оставьте заявку до конца месяца и получите дополнительную скидку на весь заказ', 'buttonText' => 'Получить скидку', 'buttonHref' => '/contacts', 'includes' => []]],
                ['type' => 'CTA',         'defaults' => ['eyebrow' => 'Готовы начать?', 'title' => 'Готовы обсудить ваш проект?', 'subtitle' => 'Оставьте заявку — мы перезвоним в течение 15 минут и ответим на все вопросы', 'primaryButton' => 'Оставить заявку', 'primaryHref' => '/contacts', 'phoneButton' => 'Позвонить нам', 'phoneHref' => 'tel:+70000000000']],
            ],
        ],
    ],

];

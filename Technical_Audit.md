# UniFood — технический и бизнес-отчёт по результатам исследования CBORD GET Food

**Версия:** 1.0
**Дата:** 24.09.2026
**Проект:** UniFood
**Контекст:** веб-приложение для заказа еды на территории университета с самовывозом

---

## 1. Цель исследования

Цель исследования — изучить доступные в открытом доступе материалы по американской платформе **CBORD GET / GET Food** и на их основе сформировать техническую и бизнес-модель для проекта **UniFood**, не копируя закрытую реализацию CBORD.

Основной интерес для UniFood представляет не весь набор функций GET, а именно подсистема:

```text
просмотр заведений
        ↓
просмотр меню
        ↓
выбор блюда
        ↓
корзина
        ↓
выбор времени самовывоза
        ↓
оформление заказа
        ↓
приём заказа персоналом
        ↓
приготовление
        ↓
готово к выдаче
        ↓
самовывоз
```

Из полного набора функций GET были сознательно исключены возможности, которые не входят в текущую область UniFood:

* университетская авторизация;
* аккаунты студентов;
* Campus ID;
* баланс Meal Plan / Dining Dollars / Campus Cash;
* мобильное удостоверение;
* генерация студенческого штрихкода;
* управление банковскими картами;
* доставка;
* интеграция с университетской системой идентификации.

При этом payment/order-management механизмы GET изучались как источник бизнес-логики, даже если соответствующие функции пока не входят в MVP UniFood.

---

# 2. Источники исследования и степень доверия к ним

Исследование основано на нескольких разных классах источников.

| Источник                                                                 | Что он позволяет узнать                                                        | Значимость для UniFood                            |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------------ | ------------------------------------------------- |
| Открытые GET Food страницы университетов                                 | Реальные пользовательские сценарии, заведения, ordering flow, pickup           | Очень высокая                                     |
| Презентация CBORD `The Next Generation of Mobile Food Ordering with GET` | Бизнес-логика GET, merchants, menus, orders, notifications, Order Manager, POS | Очень высокая                                     |
| Официальный GET Order Manager                                            | Реальное управление статусами заказов персоналом                               | Очень высокая                                     |
| CBORD Support                                                            | Конфигурацию merchants и отдельных параметров заказа                           | Высокая                                           |
| `mattorib/cbord-api`                                                     | Reverse engineering старого GET web-интерфейса                                 | Средняя                                           |
| `get-app-apple-wallet`                                                   | Реальную интеграцию стороннего сервиса с GET API                               | Средняя                                           |
| Reverse engineering GET Mobile                                           | Устройство клиентской части и локальной генерации данных                       | Низкая для UniFood ordering                       |
| Transact Mobile Ordering                                                 | Аналогичная современная campus-ordering архитектура                            | Средняя как дополнительный архитектурный источник |
| GitHub campus food projects                                              | Практические реализации menu/cart/order/admin                                  | Средняя как implementation reference              |

Важное ограничение: **полной официальной публичной технической спецификации GET Food или полного публичного ТЗ с API заказов найти не удалось**. Это принципиально важно: ниже не следует воспринимать реконструированную модель как дословную схему внутреннего backend CBORD.

---

# 3. Главный результат исследования GET Food

В 2024 году сотрудник CBORD Cameron Williams опубликовал презентацию о Mobile Ordering with GET. В ней описан непосредственно рабочий сценарий:

1. пользователь открывает GET Food;
2. выбирает merchant;
3. выбирает способ получения и время;
4. добавляет товары в корзину;
5. вводит payment information;
6. добавляет специальные инструкции;
7. отправляет заказ.

После оформления GET обрабатывает оплату, формирует receipt, сохраняет заказ и связанные транзакции в административных журналах и запускает настроенные уведомления. Для персонала существует **GET Order Manager**, позволяющий работать со статусом заказа и уведомлять пользователя о готовности.

Это даёт нам практически готовую концептуальную модель:

```text
Customer
   │
   ├── Select Merchant
   │
   ├── Select Pickup Time
   │
   ├── Select Menu Items
   │
   ├── Cart
   │
   ├── Notes
   │
   └── Submit Order
             │
             ▼
          Order
             │
             ├── Payment
             ├── Merchant
             ├── Preparation
             ├── Notifications
             └── Pickup
```

Для UniFood эта модель подходит практически напрямую после удаления campus-account и delivery частей.

---

# 4. Важное уточнение по доступу к GET

Изначально предполагалось, что изучить пользовательскую часть GET Food невозможно из-за обязательного университетского login.

Это не полностью верно.

Некоторые актуальные университетские конфигурации GET предоставляют **Guest Ordering**.

Например, UC Berkeley прямо публикует:

* возможность заказать еду без student/staff login;
* one-time login для guest ordering;
* выбор еды;
* оплату;
* планирование pickup.

University of San Diego также предоставляет отдельный публичный Guest Ordering интерфейс, где отображается список merchant'ов, их названия, расположение и возможность `Order Online`. В опубликованном списке присутствуют, например, кафе, bistro, food service locations и отдельные точки с явно указанным `Pick-up Only`.

Delta State University также предоставляет Guest Ordering с оплатой банковской картой.

Следовательно, для исследования **самой food-ordering логики** авторизация не является непреодолимым препятствием.

При этом закрытая student-specific часть по-прежнему недоступна без университетского аккаунта. Поэтому student account, meal plan и другие связанные функции мы не должны использовать как основу архитектуры UniFood.

---

# 5. Что реально видно в публичном GET Food

## 5.1. Merchant является отдельным объектом

Публичные GET страницы показывают пользователю список мест, из которых можно сделать заказ.

Например, в GET University of San Diego присутствуют отдельные merchants:

```text
Aromas Coffeehouse
Aromas Express
Bert's Bistro
Blue Spoon
Bosley Cafe
Crush Juice Co.
...
```

У merchant может отображаться:

* название;
* адрес;
* корпус;
* дополнительное название меню;
* расстояние;
* статус `OPEN`;
* возможность `Order Online`.

Для UniFood это означает, что нельзя моделировать систему как:

```text
Menu → Products
```

Лучше иметь:

```text
Campus
   ↓
DiningLocation / Merchant
   ↓
Menu
   ↓
Category
   ↓
Product
```

Даже если сейчас у нас только один университет.

---

# 6. Меню и каталог

Презентация CBORD указывает, что меню являются частью конфигурации GET Admin, а merchant имеет отдельные настройки. Для интеграции с POS CBORD также связывает GET merchant с соответствующим рестораном/точкой POS и её меню.

Это позволяет сделать следующий вывод для UniFood:

```text
DiningLocation
    ├── information
    ├── location
    ├── working hours
    ├── ordering availability
    └── menus
           ├── categories
           │      ├── drinks
           │      ├── burgers
           │      └── snacks
           │
           └── menu items
```

### Menu Item должен содержать минимум

```text
id
name
description
price
image
category_id
is_available
sort_order
```

Дополнительно:

```text
calories
weight
ingredients
allergens
```

Эти дополнительные поля не являются обязательной частью MVP, но campus dining systems часто предоставляют пользователю дополнительную информацию о блюдах. Например, современные campus-ordering решения Transact отдельно указывают nutritional information как пользовательскую возможность.

---

# 7. Кастомизация блюда

У GET есть две подтверждённые возможности:

1. **special order instructions** при оформлении заказа;
2. настройка пользовательских опций конкретных товаров.

В инструкции Hope College прямо указано, что некоторые позиции, например пицца и quesadilla, позволяют настраивать toppings.

У самого CBORD существует отдельная настройка `Order Item Notes` для merchant. Она находится в:

```text
Institutions
    → Merchants
        → Merchant
            → Ordering
                → Disable Order Item Notes
```

и используется, в частности, в контексте интеграции с MICROS.

### Следствие для UniFood

Необходимо различать:

```text
Order
    └── general_note
```

и

```text
OrderItem
    └── item_note
```

Например:

```text
Заказ №UF-1842

2 × Чизбургер
    Примечание: без лука

1 × Картофель фри
    Примечание: положить отдельно
```

Для первой версии UniFood достаточно `item_note`.

Система модификаторов может быть добавлена позже:

```text
ModifierGroup
    ├── Sauce
    │    ├── Ketchup
    │    └── BBQ
    │
    └── Toppings
         ├── Cheese
         └── Jalapeño
```

---

# 8. Время получения заказа

Выбор pickup time является одной из основных частей GET ordering flow.

CBORD в своей презентации прямо указывает выбор:

```text
order method
+
order time
```

а UMD сообщает пользователям, что при оформлении GET Food можно выбрать preferred pickup time.

Hope College дополнительно указывает возможность оформлять online orders до 7 дней заранее; конкретный лимит зависит от конфигурации конкретного учреждения и не должен рассматриваться как универсальное правило GET.

### Для UniFood

Рекомендуемая модель:

```text
PickupSlot
    id
    location_id
    date
    start_time
    end_time
    capacity
    available
```

Пользователь должен видеть:

```text
Сегодня

11:30–11:45
11:45–12:00
12:00–12:15
12:15–12:30
```

Возможна также модель:

```text
ASAP
```

но для учебного проекта фиксированные time slots проще контролировать.

---

# 9. Корзина

Основная логика:

```text
Menu Item
      ↓
Add to Cart
      ↓
Cart
      ├── item 1 × quantity
      ├── item 2 × quantity
      └── item 3 × quantity
              ↓
           subtotal
              ↓
            Order
```

## Бизнес-правило

Одна корзина должна принадлежать **одному merchant/location**.

Например:

```text
Burger Point + Coffee Shop
```

не должны превращаться в один заказ.

Лучше:

```text
Cart A → Burger Point → Order A
Cart B → Coffee Shop → Order B
```

Причина — разные точки приготовления, разные часы работы, разные pickup locations и потенциально разные временные интервалы.

Это уже адаптационное решение UniFood, а не утверждение о внутренней реализации GET.

---

# 10. Оформление заказа

Основной пользовательский flow UniFood:

```text
1. Пользователь открывает UniFood

2. Выбирает точку питания

3. Открывает меню

4. Выбирает категорию

5. Выбирает блюда

6. Добавляет блюда в корзину

7. Изменяет количества

8. Добавляет комментарии

9. Выбирает время самовывоза

10. Проверяет заказ

11. Отправляет заказ

12. Получает номер заказа

13. Следит за статусом

14. Приходит в точку выдачи

15. Получает заказ
```

В GET после submit payment обрабатывается, receipt отправляется пользователю, а информация о заказе сохраняется административной системой.

Для UniFood receipt можно первоначально заменить на экран:

```text
Заказ создан

№ UF-1842

Точка выдачи:
Главный корпус — Кафе №1

Время:
12:15–12:30

Сумма:
18.50 BYN

Статус:
Принят
```

---

# 11. Отсутствие авторизации в UniFood

Это важнейшее отличие проекта от полного GET.

## GET

```text
Student
   ↓
Login
   ↓
Campus Account
   ↓
Ordering
```

## UniFood

```text
Visitor
   ↓
UniFood
   ↓
Ordering
```

То есть **Customer Account как сущность нам не нужен**.

Но возникает другая проблема:

> Как пользователь найдёт свой заказ после обновления страницы?

Рекомендуемое решение:

### Public Order Code

При создании заказа сервер генерирует:

```text
UF-1842
```

или более длинный непредсказуемый идентификатор.

Пользователь получает:

```text
Номер заказа: UF-1842
```

и может открыть:

```text
/orders/UF-1842
```

или:

```text
Проверить заказ
→ UF-1842
```

Это позволяет реализовать tracking без пользовательского аккаунта.

### Важное следствие

Нельзя использовать просто последовательный ID:

```text
/orders/1
/orders/2
/orders/3
```

потому что это облегчает просмотр чужих заказов.

Для публичного доступа лучше использовать:

```text
public_order_code
```

с достаточной случайностью.

---

# 12. Статус заказа

Официальная презентация CBORD показывает, что после поступления заказа персонал получает возможность работать с ним через Order Manager, а пользователь получает уведомление, когда заказ готов.

Актуальное описание приложения GET Order Manager от CBORD указывает, что merchant может:

* обновлять preparation status;
* отменять заказы;
* выполнять refund;
* отправлять пользователю status updates.

## Рекомендуемая state machine UniFood

Это **проектное решение UniFood**, а не утверждение, что CBORD использует именно такие названия:

```text
CREATED
   │
   ▼
CONFIRMED
   │
   ▼
PREPARING
   │
   ▼
READY
   │
   ▼
COMPLETED
```

Отдельные ветки:

```text
CREATED ─────→ CANCELLED

CREATED ─────→ REJECTED

CONFIRMED ───→ CANCELLED
```

### Значение состояний

#### CREATED

Заказ сохранён сервером, но ещё не обработан сотрудником.

#### CONFIRMED

Сотрудник принял заказ к приготовлению.

#### PREPARING

Заказ находится в процессе приготовления.

#### READY

Заказ полностью готов и ожидает клиента.

#### COMPLETED

Заказ передан клиенту.

#### CANCELLED

Заказ был отменён.

#### REJECTED

Точка питания не может выполнить заказ.

---

# 13. Почему обязательно нужна история статусов

Недостаточно хранить только:

```text
order.status = READY
```

Лучше хранить события:

```text
CREATED
2026-09-24 11:43:01

CONFIRMED
2026-09-24 11:44:17

PREPARING
2026-09-24 11:46:02

READY
2026-09-24 11:57:34

COMPLETED
2026-09-24 12:04:11
```

Это позволяет:

* анализировать время приготовления;
* находить задержки;
* восстанавливать жизненный цикл заказа;
* показывать историю;
* строить статистику.

Для UniFood это особенно полезно с учётом требования проекта использовать relational + non-relational DB.

---

# 14. Staff / Kitchen интерфейс

Одно из наиболее полезных открытых подтверждений архитектуры GET — существование отдельного **GET Order Manager**.

CBORD описывает его как merchant-side companion application, через который персонал получает заказы, изменяет preparation status, отменяет и возвращает заказы, а пользователь получает уведомления.

Следовательно, UniFood также должен иметь отдельный интерфейс:

```text
                 UniFood
                    │
          ┌─────────┴─────────┐
          │                   │
      Customer             Staff
          │                   │
       Ordering          Order Board
                                │
                         ┌──────┴──────┐
                         │             │
                       New          Preparing
                                       │
                                     Ready
                                       │
                                   Completed
```

---

# 15. Рекомендуемый интерфейс кухни

Главная страница:

```text
┌─────────────────────────────────────────────────────┐
│                 Заказы — Кафе №1                    │
├─────────────────────────────────────────────────────┤
│ NEW              PREPARING          READY           │
│                                                     │
│ UF-1842          UF-1840            UF-1837         │
│ 3 позиции        2 позиции          1 позиция       │
│ 12:15            12:00              11:50           │
│                                                     │
│ [Принять]        [Готово]           [Выдан]         │
└─────────────────────────────────────────────────────┘
```

Для каждого заказа:

```text
UF-1842
-------------------
2 × Чизбургер
1 × Картофель
1 × Cola

Комментарий:
Без лука

Pickup:
12:15–12:30

[Принять заказ]
```

После принятия:

```text
[Начать приготовление]
```

После приготовления:

```text
[Готов]
```

При выдаче:

```text
[Выдан]
```

---

# 16. Уведомления

GET использует несколько каналов notification:

* email/fax;
* POS;
* printer;
* Order Manager.

Order Manager используется в том числе для уведомления пользователя о готовности.

Для UniFood не нужно копировать всю эту инфраструктуру.

## MVP

Достаточно:

```text
Database status
        ↓
Order status page
        ↓
polling every N seconds
```

Например:

```text
GET /api/orders/UF-1842
```

ответ:

```json
{
  "orderCode": "UF-1842",
  "status": "READY"
}
```

Пользовательская страница автоматически обновляет статус.

## Следующий этап

Можно добавить:

```text
Email
Browser Notification
WebSocket / SSE
```

Но это не должно быть обязательной частью MVP.

---

# 17. Архитектура UniFood

Для нашего проекта оптимальна **модульная монолитная архитектура**.

Не требуется:

```text
Microservice 1
Microservice 2
Message Broker
API Gateway
Kubernetes
Service Mesh
```

Для университетского проекта это создаст больше инфраструктуры, чем реальной ценности.

Рекомендуемая схема:

```text
                     Browser
                        │
             ┌──────────┴──────────┐
             │                     │
       Customer UI             Staff UI
             │                     │
             └──────────┬──────────┘
                        │
                        ▼
                  UniFood Backend
                        │
          ┌─────────────┼─────────────┐
          │             │             │
       Catalog        Orders       Admin
          │             │             │
          └─────────────┼─────────────┘
                        │
                ┌───────┴────────┐
                │                │
           PostgreSQL         MongoDB
           / MySQL
```

---

# 18. Зачем две базы данных

Поскольку в учебном проекте предусмотрено использование **реляционной и нереляционной БД**, их лучше не использовать для одних и тех же данных без причины.

## Реляционная БД

Реляционная БД должна быть источником истины для транзакционных сущностей:

```text
DiningLocation
Menu
Category
MenuItem
PickupSlot
Order
OrderItem
```

Почему:

* связи между сущностями;
* целостность;
* транзакции;
* foreign keys;
* однозначный order state;
* финансовые значения.

Для этого хорошо подходит:

```text
PostgreSQL
```

или:

```text
MySQL
```

## MongoDB

MongoDB можно использовать для:

```text
OrderEvent
NotificationEvent
AuditEvent
OperationalLog
```

Например:

```json
{
  "orderCode": "UF-1842",
  "event": "STATUS_CHANGED",
  "oldStatus": "PREPARING",
  "newStatus": "READY",
  "timestamp": "2026-09-24T11:57:34Z",
  "metadata": {
    "locationId": 2
  }
}
```

Таким образом:

```text
PostgreSQL
    ↓
Current state

MongoDB
    ↓
Event history / audit / analytics
```

Это гораздо более обоснованное использование двух разных БД, чем хранение одной и той же таблицы заказов одновременно в MySQL и MongoDB.

---

# 19. Доменная модель UniFood

## Основные сущности

```text
University
    │
    └── DiningLocation
            │
            ├── OperatingHours
            │
            └── Menu
                    │
                    ├── Category
                    │       │
                    │       └── MenuItem
                    │
                    └── MenuItem
                            │
                            └── ModifierGroup
                                    │
                                    └── ModifierOption


DiningLocation
      │
      └── PickupSlot


Order
  │
  ├── OrderItem
  │      └── MenuItem
  │
  ├── PickupSlot
  │
  └── OrderStatusHistory
```

---

# 20. Предлагаемая реляционная схема

## `universities`

```text
id
name
address
```

Если в рамках UniFood всегда используется только один университет, эта таблица может оказаться избыточной. Её можно добавить для потенциального расширения.

---

## `dining_locations`

```text
id
name
description
building
floor
address
is_active
created_at
updated_at
```

Пример:

```text
1
Кафе Главного корпуса
Главный корпус
1 этаж
```

---

## `operating_hours`

```text
id
location_id
day_of_week
open_time
close_time
```

Позволяет системе автоматически определять:

```text
OPEN
CLOSED
```

---

## `menus`

```text
id
location_id
name
description
is_active
valid_from
valid_to
```

---

## `categories`

```text
id
menu_id
name
sort_order
```

---

## `menu_items`

```text
id
category_id
name
description
price
image_url
is_available
sort_order
created_at
updated_at
```

---

## `modifier_groups`

```text
id
menu_item_id
name
required
min_select
max_select
```

---

## `modifier_options`

```text
id
modifier_group_id
name
extra_price
is_available
```

---

## `pickup_slots`

```text
id
location_id
date
start_time
end_time
capacity
reserved_count
is_available
```

---

## `orders`

```text
id
public_code
location_id
pickup_slot_id

customer_name
customer_phone

status

subtotal
discount
total

customer_note

created_at
updated_at
```

`customer_phone` или email следует делать только при наличии требования проекта. Они не являются заменой аккаунту — это обычные данные заказа.

---

## `order_items`

```text
id
order_id
menu_item_id

item_name
unit_price
quantity

item_note
subtotal
```

Очень важно хранить:

```text
item_name
unit_price
```

не только ссылку на `menu_items`.

Причина:

```text
Сегодня:
Cheeseburger = 8.50 BYN

Через месяц:
Cheeseburger = 9.20 BYN
```

Старый заказ должен продолжать отображаться как:

```text
Cheeseburger × 2
8.50 BYN
```

а не пересчитываться по новой цене.

Поэтому `OrderItem` должен содержать **snapshot данных на момент покупки**.

---

# 21. Order Events в MongoDB

Каждое изменение состояния:

```text
CREATED
CONFIRMED
PREPARING
READY
COMPLETED
CANCELLED
REJECTED
```

можно записывать в MongoDB.

Пример:

```json
{
  "order_id": 1842,
  "public_code": "UF-1842",
  "type": "ORDER_STATUS_CHANGED",
  "from": "PREPARING",
  "to": "READY",
  "created_at": "2026-09-24T11:57:34Z"
}
```

Дополнительно:

```json
{
  "order_id": 1842,
  "type": "ORDER_CREATED",
  "metadata": {
    "items_count": 4,
    "total": 18.5,
    "pickup_slot": "12:15-12:30"
  }
}
```

Это даст возможность позднее рассчитывать:

```text
среднее время приготовления
среднее время ожидания
количество заказов за день
пиковые периоды
процент отмен
самые популярные блюда
нагрузка на точки питания
```

---

# 22. REST API UniFood

## Public API

### Получить точки питания

```http
GET /api/locations
```

Ответ:

```json
[
  {
    "id": 1,
    "name": "Кафе Главного корпуса",
    "status": "OPEN"
  }
]
```

---

### Получить точку

```http
GET /api/locations/{id}
```

---

### Получить меню

```http
GET /api/locations/{id}/menu
```

---

### Получить блюдо

```http
GET /api/menu-items/{id}
```

---

### Создать заказ

```http
POST /api/orders
```

Пример:

```json
{
  "location_id": 1,
  "pickup_slot_id": 32,
  "customer_name": "Константин",
  "items": [
    {
      "menu_item_id": 14,
      "quantity": 2,
      "note": "Без лука"
    },
    {
      "menu_item_id": 27,
      "quantity": 1
    }
  ],
  "customer_note": "Подготовить к указанному времени"
}
```

Ответ:

```json
{
  "order_code": "UF-1842",
  "status": "CREATED",
  "total": 18.50
}
```

---

### Получить статус заказа

```http
GET /api/orders/{public_code}
```

Ответ:

```json
{
  "order_code": "UF-1842",
  "status": "READY",
  "location": "Кафе Главного корпуса",
  "pickup_time": "12:15-12:30"
}
```

---

# 23. Staff API

### Получить новые заказы

```http
GET /api/staff/orders?status=CREATED
```

### Получить текущие заказы

```http
GET /api/staff/orders/active
```

### Изменить статус

```http
PATCH /api/staff/orders/{id}/status
```

```json
{
  "status": "PREPARING"
}
```

или:

```json
{
  "status": "READY"
}
```

или:

```json
{
  "status": "COMPLETED"
}
```

---

# 24. Admin API

Для управления каталогом:

```http
POST   /api/admin/locations
PATCH  /api/admin/locations/{id}

POST   /api/admin/categories
PATCH  /api/admin/categories/{id}

POST   /api/admin/menu-items
PATCH  /api/admin/menu-items/{id}
DELETE /api/admin/menu-items/{id}

POST   /api/admin/pickup-slots
PATCH  /api/admin/pickup-slots/{id}
```

Однако есть важное ограничение:

> В UniFood отсутствует механизм авторизации.

Поэтому полноценный production-grade public admin API с такими маршрутами нельзя считать безопасным.

Для учебной версии необходимо явно зафиксировать, что admin/staff interface является частью демонстрационной системы и не предполагает полноценного identity management.

---

# 25. Валидация заказа

Перед созданием `Order` backend должен выполнить повторную проверку всех условий.

Нельзя доверять данным frontend.

Сервер должен проверить:

```text
✓ существует location
✓ location активен
✓ location открыт
✓ menu активно
✓ item существует
✓ item доступен
✓ quantity > 0
✓ pickup slot существует
✓ pickup slot относится к location
✓ pickup slot доступен
✓ цена актуальна
```

После этого backend создаёт заказ в транзакции.

---

# 26. Почему нельзя доверять цене с frontend

Frontend может прислать:

```json
{
  "menu_item_id": 14,
  "price": 1.00
}
```

даже если реальная цена:

```text
8.50 BYN
```

Поэтому frontend должен передавать:

```text
menu_item_id
quantity
```

а backend самостоятельно получает:

```text
current price
```

из БД.

После создания заказа стоимость фиксируется в `order_items`.

---

# 27. Правила доступности блюд

Блюдо должно иметь:

```text
is_available
```

Например:

```text
Burger       AVAILABLE
Soup         UNAVAILABLE
Coffee       AVAILABLE
```

Если позиция закончилась, staff должен иметь возможность установить:

```text
is_available = false
```

и пользователь сразу не должен иметь возможность оформить её.

Это особенно важно для реальной точки питания.

---

# 28. Важное бизнес-правило: закрытие точки

Если:

```text
current_time > close_time
```

то:

```text
OPEN = false
```

и кнопка заказа должна исчезнуть/стать недоступной.

Например:

```text
Кафе №1
Открыто до 19:00

Сейчас 19:05

[Заказать]
```

не должно быть возможно.

---

# 29. Важное бизнес-правило: pickup cutoff

Полезно ввести дополнительное правило:

```text
closing_time
-
preparation_buffer
```

Например:

```text
Кафе закрывается в 19:00
Минимальное время приготовления = 20 минут

Последний pickup:
18:40
```

Это адаптация бизнес-логики реальной точки питания.

---

# 30. Capacity pickup slots

Если:

```text
12:15–12:30
capacity = 10
```

и уже:

```text
reserved_count = 10
```

слот должен стать:

```text
FULL
```

и исчезнуть из пользовательского выбора.

Это значительно полезнее, чем просто хранить произвольное время.

---

# 31. Полный бизнес-процесс

## Customer flow

```text
OPEN WEBSITE
    ↓
SELECT DINING LOCATION
    ↓
VIEW MENU
    ↓
SELECT CATEGORY
    ↓
VIEW MENU ITEM
    ↓
ADD ITEM
    ↓
VIEW CART
    ↓
CHANGE QUANTITY / NOTES
    ↓
SELECT PICKUP SLOT
    ↓
CHECK ORDER
    ↓
SUBMIT
    ↓
GENERATE ORDER CODE
    ↓
SHOW ORDER STATUS
```

## Staff flow

```text
NEW ORDER
    ↓
CONFIRM
    ↓
PREPARING
    ↓
READY
    ↓
CUSTOMER ARRIVES
    ↓
COMPLETED
```

## Exceptional flows

```text
NEW
 ├── REJECTED
 └── CANCELLED
```

---

# 32. Customer UI

Рекомендуемая структура:

```text
/
├── Главная
│
├── /locations
│     └── список точек питания
│
├── /locations/{id}
│     └── меню
│
├── /menu-items/{id}
│     └── карточка блюда
│
├── /cart
│     └── корзина
│
├── /checkout
│     └── оформление
│
└── /orders/{code}
      └── статус заказа
```

---

# 33. Главная страница

Главная задача — не перегружать пользователя.

```text
UniFood

Где хотите заказать?

┌─────────────────────────────┐
│ Кафе Главного корпуса       │
│ 1 этаж                      │
│ Открыто                     │
│                             │
│ [Открыть меню]              │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Буфет                       │
│ 2 этаж                      │
│ Закрыто                     │
└─────────────────────────────┘
```

Для MVP поиск по блюдам можно отложить.

---

# 34. Страница меню

```text
Кафе Главного корпуса

[Бургеры] [Напитки] [Снэки]

--------------------------------

Чизбургер
Говяжья котлета, сыр, овощи

8.50 BYN

[Добавить]

--------------------------------

Картофель фри

4.00 BYN

[Добавить]
```

---

# 35. Карточка блюда

```text
Чизбургер

8.50 BYN

Говяжья котлета
Сыр
Овощи
Соус

Количество:
[-] 2 [+]

Комментарий:
[ Без лука             ]

[Добавить в корзину]
```

---

# 36. Корзина

```text
Корзина

Чизбургер
2 × 8.50 = 17.00 BYN

Картофель
1 × 4.00 = 4.00 BYN

----------------------
Итого: 21.00 BYN

Время самовывоза:

[12:15–12:30]

[Оформить заказ]
```

---

# 37. Страница заказа

После создания заказа:

```text
Ваш заказ

UF-1842

Кафе Главного корпуса

Самовывоз:
12:15–12:30

Статус:

● Заказ принят
○ Приготовление
○ Готов
○ Выдан
```

После READY:

```text
Ваш заказ готов!

UF-1842

Заберите заказ
в Кафе Главного корпуса.
```

---

# 38. Почему статусная шкала важнее сложного tracking

Для самовывоза нам не нужен:

```text
GPS
карта
courier tracking
route tracking
live location
```

Вместо этого достаточно:

```text
CREATED
    ↓
CONFIRMED
    ↓
PREPARING
    ↓
READY
    ↓
COMPLETED
```

Это соответствует сути campus pickup: пользователь хочет прежде всего знать:

> «Мне уже идти за едой или ещё нет?»

---

# 39. Payment

GET официально поддерживает оплату как часть order flow, включая payment information и интеграцию с campus-account/POS системами.

Однако **онлайн-оплата не должна автоматически становиться частью MVP UniFood**, если она отсутствует в требованиях проекта.

Предлагаемая архитектура:

```text
Payment
    ├── NOT_REQUIRED
    ├── PAY_AT_PICKUP
    └── ONLINE
```

Для MVP:

```text
PAY_AT_PICKUP
```

или вообще:

```text
Payment module = future
```

Если будет добавлена онлайн-оплата, нельзя хранить:

```text
номер банковской карты
CVV
PIN
```

в собственной БД.

---

# 40. POS integration

Одно из самых интересных технических свойств GET — интеграция с POS.

CBORD описывает сценарий, в котором:

```text
GET
 ↓
Merchant
 ↓
MICROS RVC
 ↓
MICROS database
 ↓
Kitchen printer/display
```

Заказ из GET может передаваться в POS так, чтобы он обрабатывался аналогично заказу из register.

Для UniFood это **future scope**, потому что у нас нет необходимости подключать реальный POS.

Однако архитектуру можно заранее построить так:

```text
OrderService
     │
     ├── Internal fulfillment
     │
     └── POS Integration
```

Позднее можно было бы добавить:

```text
POST /integrations/pos/orders
```

без изменения core order model.

---

# 41. Printer / Kitchen integration

GET поддерживает несколько вариантов доставки информации персоналу:

```text
email/fax
printer
MICROS
Order Manager
```

Для UniFood MVP рекомендуется:

```text
Browser Staff Dashboard
```

вместо:

```text
Printer integration
```

Причина — это существенно уменьшает инфраструктуру и оставляет то же самое основное бизнес-действие:

```text
order arrives
→ employee sees it
→ preparation
→ ready
```

---

# 42. Reverse engineering: что мы реально узнали

## 42.1. `mattorib/cbord-api`

Репозиторий описывает старую реализацию доступа к CBORD GET.

Автор прямо указывает, что это не официальный API, а имитация browser HTTP requests с последующим scraping ответа. Проект был архивирован 1 сентября 2021 года.

Примерно такой принцип:

```text
Client
   ↓
cbord-api wrapper
   ↓
HTTP request
   ↓
CBORD GET website
   ↓
HTML/response
   ↓
Parsing
   ↓
JSON
```

Это крайне интересная архитектурная находка.

Но конкретные endpoint'ы репозитория:

```text
/login
/balances
/transactions
/all
```

ориентированы прежде всего на аккаунт и транзакции, а не на современный food-ordering API.

### Следствие

`cbord-api` нельзя использовать как:

> «готовую спецификацию API GET Food».

Но его можно использовать как доказательство того, что исторически web-интерфейс GET представлял собой приложение, с которым сторонний клиент мог взаимодействовать через HTTP, а отсутствие официального API приводило к scraping/reverse engineering.

---

# 43. `get-app-apple-wallet`

Современный GitHub-проект `Mcrich23/get-app-apple-wallet` использует CBORD GET API для получения barcode/balance информации. Репозиторий описывает отдельный `getClient.js`, взаимодействие с CBORD GET API и server-side хранение некоторых сессионных данных.

Это показывает важный архитектурный принцип:

```text
Custom Client
      ↓
GET Client
      ↓
CBORD backend
```

Но данный проект снова ориентирован на:

```text
barcode
balance
campus identity
```

а не на ordering.

Поэтому для UniFood его значение ограничено.

---

# 44. Reverse engineering GET Mobile

Исследование Jason He показывает, что в конкретной реализации GET Mobile некоторые операции выполняются **не через отдельный серверный endpoint**, а внутри самого клиента.

В частности, автор исследовал генерацию PDF417 barcode и восстановил структуру данных, включая timestamp и вычисление производных значений.

Похожее исследование ShareBRB показывает, что значительная часть GET Mobile была реализована JavaScript-кодом внутри клиентского приложения и что reverse engineer смог восстановить внутренние функции генерации barcode.

### Что это даёт UniFood

Не конкретный API ordering.

Но это важное архитектурное наблюдение:

> нельзя предполагать, что абсолютно вся логика GET находится на backend.

Некоторые функции могут быть реализованы client-side.

Для UniFood это означает, что:

```text
UI logic
```

может находиться на frontend, но критические бизнес-правила:

```text
price
availability
pickup slot
order state
```

обязательно должны проверяться backend.

---

# 45. Официальный CBORD Support как источник технических деталей

Очень полезный пример — документация по `Order Item Notes`.

Она подтверждает наличие:

```text
Institutions
    → Merchants
        → Merchant settings
            → Ordering
```

То есть merchant — не просто строка в каталоге.

Это конфигурируемая сущность с собственными ordering-настройками.

Для UniFood это подтверждает необходимость:

```text
DiningLocation
```

с параметрами:

```text
is_active
ordering_enabled
item_notes_enabled
```

а не только:

```text
name
address
```

---

# 46. Transact Mobile Ordering как дополнительный источник

Transact — это **не CBORD GET**.

Поэтому нельзя использовать документацию Transact как доказательство того, что именно GET реализован так же.

Но Transact является полезным независимым источником по campus food ordering.

Официальная документация Transact описывает централизованное управление:

```text
transactions
items
pricing
reporting
```

и интеграцию mobile ordering с campus commerce.

Другие материалы Transact указывают:

* customization;
* mobile ordering;
* pickup;
* notifications;
* nutritional information;
* интеграцию с POS;
* работу с большим количеством campus locations.

### Что взять в UniFood

Не копировать Transact.

Использовать его как вторую независимую проверку:

```text
merchant
menu
item
customization
order
pickup
notification
staff
POS
```

Это укрепляет нашу концептуальную модель.

---

# 47. Открытые GitHub campus projects

Дополнительные проекты показывают, как другие разработчики реализуют похожие системы.

Например, `CampusDining` использует:

```text
PHP
MySQL
Admin panel
Cart
Food items
Orders
```

и создан непосредственно как campus food-ordering project.

`CampusEats` реализует campus pre-order систему на:

```text
React
Spring Boot
MongoDB
```

с:

```text
canteens
food
orders
payments
QR pickup
```

но при этом использует authentication и другие функции, которые не нужны нашему текущему проекту.

`Canteen Flow` отдельно демонстрирует:

```text
canteens
menus
cart
checkout
order status
staff management
menu management
```

и даже guest mode.

### Вывод

GitHub-проекты полезны для:

```text
implementation patterns
folder structure
REST API
database schema
frontend components
staff dashboard
```

но не являются доказательством архитектуры CBORD GET.

---

# 48. Бизнес-модель UniFood

## Проблема

Традиционное питание в университете:

```text
Student
   ↓
стойка
   ↓
очередь
   ↓
заказ
   ↓
ожидание
   ↓
получение
```

UniFood:

```text
Student
   ↓
Web
   ↓
предварительный заказ
   ↓
приготовление
   ↓
готово
   ↓
самовывоз
```

Главная бизнес-ценность:

```text
Снижение времени ожидания в точке питания
```

Это также соответствует основному назначению GET mobile ordering: ordering ahead и skip the line. UMD прямо описывает GET Food как способ заказать заранее и избежать очереди, а Cornell — как способ сделать посещение точки питания эффективнее за счёт предварительного заказа и pickup station.

---

# 49. Пользовательская ценность

Пользователь получает:

```text
1. Просмотр меню заранее
2. Понятные цены
3. Выбор еды до похода в кафе
4. Возможность сформировать заказ заранее
5. Выбор pickup slot
6. Отсутствие необходимости стоять в очереди
7. Понимание статуса заказа
8. Быстрый pickup
```

---

# 50. Ценность для точки питания

Для staff:

```text
1. Заказ приходит заранее
2. Можно увидеть точное содержимое
3. Можно начать приготовление заранее
4. Можно контролировать очередь заказов
5. Можно отмечать готовность
6. Можно фиксировать завершение заказа
```

Описываемая CBORD модель Order Manager именно так и организует backend-to-kitchen workflow.

---

# 51. Основные функциональные требования UniFood

## FR-01. Просмотр точек питания

Система должна предоставлять список доступных точек питания университета.

---

## FR-02. Просмотр статуса точки

Для каждой точки отображается:

```text
OPEN
CLOSED
```

---

## FR-03. Просмотр меню

Пользователь должен иметь возможность открыть меню выбранной точки питания.

---

## FR-04. Просмотр категории

Меню должно поддерживать категории блюд.

---

## FR-05. Просмотр блюда

Для блюда отображаются:

```text
название
описание
цена
изображение
доступность
```

---

## FR-06. Добавление в корзину

Пользователь может добавить блюдо и указать количество.

---

## FR-07. Изменение корзины

Пользователь может:

```text
increase quantity
decrease quantity
remove item
```

---

## FR-08. Комментарии

Пользователь может добавить комментарий к отдельной позиции.

---

## FR-09. Выбор времени pickup

Пользователь выбирает доступный интервал самовывоза.

---

## FR-10. Создание заказа

Система создаёт новый Order после успешной серверной валидации.

---

## FR-11. Генерация order code

Каждый заказ получает уникальный публичный код.

Например:

```text
UF-1842
```

---

## FR-12. Просмотр статуса

Пользователь может получить текущий статус заказа по публичному коду.

---

## FR-13. Получение заказа staff

Сотрудник должен видеть новые заказы своей точки.

---

## FR-14. Изменение статуса

Staff может изменять:

```text
CREATED
→ CONFIRMED
→ PREPARING
→ READY
→ COMPLETED
```

---

## FR-15. Отмена

Заказ может быть отменён при соблюдении соответствующих ограничений.

---

## FR-16. Управление меню

Администратор/оператор должен иметь возможность:

```text
создавать блюда
редактировать блюда
изменять цены
включать/выключать доступность
```

---

# 52. Нефункциональные требования

## NFR-01. Производительность

Страница меню должна загружаться быстро при обычной campus-нагрузке.

---

## NFR-02. Консистентность

Order total должен вычисляться backend.

---

## NFR-03. Безопасность

Пользователь не должен иметь возможность:

```text
изменить цену
создать заказ из несуществующего товара
заказать недоступный товар
выбрать чужой pickup slot
получить чужой заказ по предсказуемому ID
```

---

## NFR-04. Надёжность

Создание заказа должно быть транзакционным:

```text
Order
+
OrderItems
+
Pickup reservation
```

либо создаются вместе, либо операция откатывается.

---

## NFR-05. Аудит

Изменения состояния заказа должны записываться.

---

## NFR-06. Масштабируемость

Архитектура должна позволять добавить:

```text
новый merchant
новую точку
новое меню
новую категорию
новые modifier options
новые pickup slots
```

без изменения основной бизнес-логики.

---

# 53. Ограничения текущего UniFood

Не входят в текущий MVP:

```text
❌ user registration
❌ login
❌ student account
❌ campus ID
❌ meal plans
❌ balance
❌ campus cash
❌ mobile ID
❌ QR student identity
❌ delivery
❌ courier
❌ GPS tracking
❌ loyalty
❌ promotions
❌ full payment gateway
❌ POS integration
```

Это позволяет сохранить проект достаточно реалистичным, но не превратить его в копию полной campus-commerce системы.

---

# 54. Что можно добавить после MVP

### Phase 2

```text
Email notifications
Order history
Reorder
Item modifiers
Nutritional information
Search
Favorites
```

GET, например, поддерживает просмотр и повторение прошлых заказов в некоторых университетских конфигурациях. Barry University прямо указывает возможность `reorder past orders`, а Hope College — просмотр и повторение recent orders.

---

### Phase 3

```text
Online payment
QR pickup
WebSocket/SSE
POS integration
Analytics
```

---

# 55. Order History без аккаунтов

Это интересный вопрос.

Полноценный history:

```text
My Orders
```

невозможен без identity management.

Но можно реализовать:

```text
Order Code
```

или хранить последние заказы в browser:

```text
localStorage
```

Например:

```text
Мои заказы на этом устройстве

UF-1842
UF-1801
UF-1776
```

Это не серверная идентификация пользователя.

---

# 56. Reorder

Если позднее потребуется аналог GET `Reorder`, можно сделать:

```text
UF-1842

[Повторить заказ]
```

Backend создаёт новую корзину на основании старого order snapshot.

При этом необходимо повторно проверить:

```text
item exists
item available
current price
merchant open
pickup slot available
```

Старый заказ никогда не должен просто копироваться как готовая транзакция.

---

# 57. Аналитика

Собранные Order Events позволят получать:

```text
Orders/day
Revenue/day
Average preparation time
Average pickup delay
Popular menu items
Peak hours
Cancelled orders
Rejected orders
```

Пример:

```text
11:00–12:00     18 orders
12:00–13:00     61 orders
13:00–14:00     47 orders
14:00–15:00     23 orders
```

Это уже полезный компонент администратора, а не просто CRUD.

---

# 58. Как связать техническую и бизнес-модель

Главная цепочка UniFood:

```text
BUSINESS

Student wants food quickly
          ↓
Browse menu
          ↓
Choose food
          ↓
Pre-order
          ↓
Choose pickup time
          ↓
Kitchen prepares order
          ↓
Order becomes ready
          ↓
Student picks it up
```

Техническая реализация:

```text
DiningLocation
     ↓
Menu
     ↓
MenuItem
     ↓
Cart
     ↓
Order
     ↓
OrderItem
     ↓
PickupSlot
     ↓
OrderStatus
     ↓
OrderEvent
```

---

# 59. Итоговая архитектура

```text
┌────────────────────────────────────────────────────┐
│                    UniFood UI                      │
│                                                    │
│  Customer                         Staff             │
│  ────────                         ─────             │
│  Locations                        Orders            │
│  Menus                            Preparing         │
│  Product                          Ready             │
│  Cart                             Completed         │
│  Checkout                                             │
│  Order Status                                       │
└───────────────────────┬────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────┐
│                 UniFood Backend                    │
│                                                    │
│ Catalog Service                                    │
│ Order Service                                      │
│ Pickup Service                                     │
│ Menu Management                                    │
│ Staff Order Management                             │
│                                                    │
└───────────────────────┬────────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │                   │
              ▼                   ▼
       ┌─────────────┐     ┌──────────────┐
       │ PostgreSQL  │     │   MongoDB    │
       │             │     │              │
       │ Catalog     │     │ OrderEvents  │
       │ Orders      │     │ Audit        │
       │ Pickup      │     │ Analytics    │
       └─────────────┘     └──────────────┘
```

---

# 60. Рекомендуемая структура проекта

Если UniFood остаётся на PHP:

```text
UniFood/
│
├── public/
│   ├── index.php
│   ├── assets/
│   └── js/
│
├── src/
│   ├── Controllers/
│   ├── Services/
│   ├── Repositories/
│   ├── Models/
│   ├── DTO/
│   └── Validation/
│
├── config/
│
├── database/
│   ├── migrations/
│   └── seeders/
│
├── admin/
│
├── staff/
│
├── views/
│   ├── locations/
│   ├── menu/
│   ├── cart/
│   ├── checkout/
│   └── orders/
│
├── docs/
│   └── architecture.md
│
├── docker-compose.yml
└── README.md
```

Главное — не смешивать:

```text
SQL
HTML
business logic
```

в одном `.php` файле.

---

# 61. Что не следует копировать из GET

Исследование полного GET показало очень большой набор функций, которые относятся к campus commerce, но не нужны UniFood:

```text
Campus accounts
Meal plans
Balances
Campus Cash
Mobile ID
Barcode
Parents/Guardians
Deposits
Automatic deposits
Campus card
POS
Card readers
```

В официальных материалах CBORD эти компоненты составляют часть более крупной экосистемы GET, а не обязательную часть food ordering.

UniFood должен реализовывать именно:

```text
Food Ordering
+
Pickup
+
Kitchen Workflow
```

---

# 62. Что следует взять из GET

## Обязательно

```text
Merchant / Dining Location
Menu
Menu Items
Cart
Order
Pickup Time
Order Notes
Order Status
Staff Order Management
Ready notification/status
Order History in backend
```

Эти элементы подтверждаются открытыми материалами GET и университетскими инструкциями.

## Желательно

```text
Item customization
Availability
Scheduled ordering
Order history
Reorder
Operational analytics
```

## Позже

```text
Payment
POS
QR pickup
Push notifications
Nutrition
Promotions
```

---

# 63. Что известно о GET наверняка, а что нет

## Подтверждено

* GET Food позволяет выбирать merchant.
* GET поддерживает pickup.
* GET поддерживает выбор времени заказа/pickup.
* В заказ можно добавлять специальные инструкции.
* Заказ поступает в административную часть.
* Существуют merchant settings и ordering settings.
* Существует отдельный GET Order Manager для персонала.
* Staff может менять preparation status.
* Staff может отменять/возвращать заказы.
* Пользователь может получать status updates.
* В некоторых конфигурациях существует guest ordering.
* GET способен интегрироваться с POS/MICROS.

## Не подтверждено публичными источниками

* точная современная схема БД GET Food;
* полный официальный REST/GraphQL API ordering;
* точные названия внутренних order statuses;
* внутренняя структура современных order objects;
* точная реализация pickup capacity;
* точная структура notification backend;
* точная внутренняя микросервисная архитектура.

Поэтому такие детали в настоящем документе являются **архитектурными решениями UniFood**, а не утверждениями о закрытом backend CBORD.

---

# 64. Итоговое решение для UniFood

После исследования GET Food наиболее обоснованная модель UniFood выглядит следующим образом:

```text
                    USER
                     │
                     ▼
              Dining Locations
                     │
                     ▼
                   Menu
                     │
                     ▼
                 Menu Item
                     │
                     ▼
                   Cart
                     │
                     ▼
              Pickup Slot
                     │
                     ▼
                  ORDER
                     │
          ┌──────────┼──────────┐
          ▼          ▼          ▼
      Confirmed   Preparing    Cancelled
                     │
                     ▼
                   Ready
                     │
                     ▼
                 Completed
```

При этом отсутствие login **не является архитектурной проблемой для основного customer flow**.

Вместо:

```text
User Account
```

используется:

```text
Order Public Code
```

Вместо:

```text
Campus Account
```

используется:

```text
Anonymous Customer Session
```

Вместо:

```text
GET Order Manager
```

используется:

```text
UniFood Staff Dashboard
```

Вместо:

```text
CBORD / MICROS
```

используется:

```text
UniFood Order Service
```

---

# 65. Финальная концепция MVP

### Customer

```text
Открыть UniFood
→ выбрать кафе
→ выбрать меню
→ выбрать блюда
→ корзина
→ pickup slot
→ создать заказ
→ получить UF-XXXX
→ отслеживать статус
→ забрать еду
```

### Staff

```text
Открыть Staff Dashboard
→ увидеть новый заказ
→ принять
→ готовить
→ отметить Ready
→ выдать
→ отметить Completed
```

### System

```text
PostgreSQL
    ↓
текущее состояние системы

MongoDB
    ↓
события и аудит

Backend
    ↓
валидация всей бизнес-логики

Frontend
    ↓
UI / polling статуса
```

---

# 66. Ключевой вывод исследования

**UniFood не должен быть копией всего CBORD GET.**

Из исследования видно, что GET — это более крупная campus-commerce платформа, внутри которой food ordering является одним из модулей. GET дополнительно объединяет campus accounts, payment infrastructure, mobile ID, POS и другие сервисы.

Для нашего проекта достаточно выделить ядро:

```text
MERCHANT
    ↓
MENU
    ↓
CART
    ↓
ORDER
    ↓
PICKUP
    ↓
KITCHEN
    ↓
ORDER STATUS
```

Именно эта часть представляет собой наиболее полезный и реалистичный фундамент для **белорусского университетского аналога UniFood**.

---

# 67. Основные использованные материалы

### CBORD / GET

* **CBORD — The Next Generation of Mobile Food Ordering with GET** — презентация сотрудника CBORD, содержащая ordering flow, merchant/menu configuration, notifications, Order Manager и POS integration.
  [Открыть презентацию](https://www.slideserve.com/maudej/the-next-generation-of-mobile-food-ordering-with-get-powerpoint-ppt-presentation?utm_source=chatgpt.com)

* **CBORD GET Order Manager** — официальное описание merchant-side приложения для управления preparation status, cancellation/refund и notifications.
  [Открыть описание GET Order Manager](https://apps.apple.com/us/app/get-order-manager/id1626345825?utm_source=chatgpt.com)

* **CBORD Support — Order Item Notes** — пример официальной merchant-level ordering configuration.
  [Открыть документацию CBORD](https://support.cbord.com/hc/en-us/articles/37730117304731-GET-How-to-Enable-Disable-Order-Item-Notes-by-Merchant?utm_source=chatgpt.com)

* **GET public Guest Ordering — UC Berkeley** — публичный ordering flow без student login.

* **GET public Guest Ordering — University of San Diego** — публичный список merchants и Order Online.

* **GET Food — University of Minnesota Duluth** — pickup time и campus dining ordering flow.

* **GET Mobile — Hope College** — выбор vendor/pickup time, cart, customization, future ordering и reorder.

* **GET Food — Barry University** — menu browsing и reorder past orders.

### Reverse engineering

* **mattorib/cbord-api** — reverse-engineered исторический CBORD GET web interface.
  [Открыть GitHub repository](https://github.com/mattorib/cbord-api?utm_source=chatgpt.com)

* **Mcrich23/get-app-apple-wallet** — современный сторонний клиент взаимодействия с CBORD GET API.
  [Открыть GitHub repository](https://github.com/Mcrich23/get-app-apple-wallet?utm_source=chatgpt.com)

* **Jason He — Reverse Engineering CBORD GET Mobile Barcode Generation** — исследование внутренней логики GET Mobile.
  [Открыть исследование](https://jsnhe.com/blog/reverse-engineering-cbord-barcode-generation?utm_source=chatgpt.com)

* **ShareBRB / GET App Hack** — reverse engineering клиентской JavaScript-части GET Mobile.
  [Открыть исследование](https://ibiyemiabiodun.com/projects/get-app-hack/?utm_source=chatgpt.com)

### Дополнительные campus-ordering источники

* **Transact Mobile Ordering** — независимая campus-commerce модель для проверки архитектурных решений.

* **CampusDining** — открытая PHP/MySQL реализация campus food ordering.
  [Открыть GitHub repository](https://github.com/Akash-Nadigepu/CampusDining?utm_source=chatgpt.com)

* **CampusEats** — full-stack campus food pre-order implementation.
  [Открыть GitHub repository](https://github.com/Ravindu-Dev/CampusEats?utm_source=chatgpt.com)

---

# 68. Рекомендуемый следующий документ проекта

На основании данного исследования уже можно перейти от исследовательского отчёта к **формальному ТЗ UniFood**.

Следующим документом логично сделать:

```text
UniFood Technical Specification

1. Scope
2. Actors
3. Functional Requirements
4. Business Rules
5. Use Cases
6. User Stories
7. Order State Machine
8. Database ER Model
9. REST API Specification
10. Customer UI
11. Staff UI
12. Admin UI
13. PostgreSQL schema
14. MongoDB schema
15. Sequence diagrams
16. Validation rules
17. Error handling
18. Non-functional requirements
19. MVP / Future scope
```

Такой документ уже будет не исследованием GET Food, а **полноценным проектным ТЗ непосредственно для разработки UniFood**.

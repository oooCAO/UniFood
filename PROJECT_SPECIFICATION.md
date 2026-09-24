# UniFood — рабочий технический документ

**Версия:** 1.1
**Дата:** 24.09.2026
**Назначение:** основа для ТЗ, проектирования БД, API и UI

---

## 1. Цель проекта

**UniFood** — веб-приложение для предварительного заказа еды в университетских точках питания с **самовывозом**.

Основной сценарий:

```text
Точка питания
    ↓
Меню
    ↓
Блюдо
    ↓
Корзина
    ↓
Время самовывоза
    ↓
Заказ
    ↓
Приготовление
    ↓
Готово
    ↓
Выдача
```

Проект ориентирован на модель campus food ordering, изученную на примере CBORD GET Food, но не является его копией.

---

# 2. Границы проекта

## Входит в MVP

* точки питания;
* меню;
* категории;
* блюда;
* доступность блюд;
* корзина;
* комментарии к позициям;
* выбор времени самовывоза;
* оформление заказа;
* публичный номер заказа;
* отслеживание статуса;
* интерфейс сотрудника точки питания;
* управление меню;
* история событий заказа.

## Не входит в MVP

* регистрация;
* login/authentication;
* аккаунты студентов;
* Campus ID;
* Meal Plan / Campus Cash;
* доставка;
* курьеры;
* GPS;
* онлайн-оплата;
* POS;
* QR-пропуска;
* loyalty/bonus;
* push-уведомления.

---

# 3. Что берём из исследования GET Food

Исследование GET подтверждает полезность следующей модели:

```text
Merchant
  → Menu
  → Menu Items
  → Cart
  → Order
  → Pickup
  → Order Management
  → Status Updates
```

GET также использует отдельный merchant-side интерфейс для обработки заказов и изменения их состояния. ([CBORD / GET Order Manager](https://apps.apple.com/us/app/get-order-manager/id1626345825))

Открытые университетские материалы подтверждают сценарии выбора точки питания, меню, pickup и предварительного заказа. ([University of Minnesota Duluth](https://dining-services.d.umn.edu/welcome-to-dining), [Hope College](https://hope.edu/offices/dining-services/menus-locations/mobile.html))

**Не копируем:** campus accounts, balances, mobile ID и другие функции полной GET-платформы.

---

# 4. Роли

## Customer

Может:

* просматривать точки питания;
* смотреть меню;
* добавлять блюда;
* редактировать корзину;
* выбирать pickup slot;
* создавать заказ;
* получать номер заказа;
* смотреть статус.

## Staff

Может:

* видеть новые заказы;
* принимать заказ;
* переводить заказ в `PREPARING`;
* отмечать `READY`;
* отмечать `COMPLETED`;
* отменять/отклонять заказ.

## Admin

Может:

* управлять точками питания;
* управлять меню;
* управлять категориями;
* управлять блюдами;
* менять цены;
* включать/выключать доступность;
* создавать pickup slots.

> В текущем проекте authentication отсутствует, поэтому разделение Staff/Admin относится к логике интерфейсов и проектному требованию, а не к полноценной системе identity/access management.

---

# 5. Основной пользовательский flow

```text
1. Открыть UniFood
2. Выбрать точку питания
3. Открыть меню
4. Выбрать блюда
5. Добавить в корзину
6. Изменить количество / комментарии
7. Выбрать время самовывоза
8. Проверить заказ
9. Создать заказ
10. Получить публичный код UF-XXXX
11. Смотреть статус
12. Забрать заказ
```

---

# 6. Order State Machine

Рекомендуемая модель:

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

Дополнительные ветки:

```text
CREATED ──→ CANCELLED
CREATED ──→ REJECTED
```

### Значения

| Статус      | Значение                       |
| ----------- | ------------------------------ |
| `CREATED`   | заказ создан                   |
| `CONFIRMED` | сотрудник принял заказ         |
| `PREPARING` | заказ готовится                |
| `READY`     | заказ готов к выдаче           |
| `COMPLETED` | заказ выдан                    |
| `CANCELLED` | заказ отменён                  |
| `REJECTED`  | точка не может выполнить заказ |

Названия являются **моделью UniFood**, а не утверждением о точных внутренних названиях GET.

---

# 7. Доменная модель

```text
DiningLocation
    ↓
Menu
    ↓
Category
    ↓
MenuItem

DiningLocation
    ↓
PickupSlot

Order
    ├── OrderItem
    ├── PickupSlot
    └── Status Events
```

## Основные сущности

### DiningLocation

```text
id
name
description
building
floor
address
is_active
ordering_enabled
```

### Menu

```text
id
location_id
name
description
is_active
valid_from
valid_to
```

### Category

```text
id
menu_id
name
sort_order
```

### MenuItem

```text
id
category_id
name
description
price
image_url
is_available
sort_order
```

### PickupSlot

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

### Order

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

### OrderItem

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

`item_name` и `unit_price` должны сохраняться как snapshot на момент заказа.

---

# 8. Две базы данных

## PostgreSQL / MySQL

Используется для основной транзакционной модели:

```text
DiningLocation
Menu
Category
MenuItem
PickupSlot
Order
OrderItem
```

## MongoDB

Используется для событий и аудита:

```text
ORDER_CREATED
STATUS_CHANGED
ORDER_CANCELLED
ORDER_REJECTED
ORDER_COMPLETED
```

Пример:

```json
{
  "order_id": 1842,
  "event": "STATUS_CHANGED",
  "from": "PREPARING",
  "to": "READY",
  "timestamp": "2026-09-24T11:57:34Z"
}
```

Такое разделение даёт:

```text
SQL → текущее состояние
MongoDB → история событий
```

---

# 9. Отсутствие авторизации

Так как UniFood не использует login, пользователь идентифицирует заказ через:

```text
public_order_code
```

Например:

```text
UF-1842
```

Публичный URL:

```text
/orders/UF-1842
```

Нельзя использовать предсказуемый database ID как публичный идентификатор.

Например:

```text
/orders/1
/orders/2
/orders/3
```

лучше не использовать.

---

# 10. Корзина

Одна корзина = одна точка питания.

```text
Cart
 ├── MenuItem
 ├── quantity
 ├── note
 └── subtotal
```

Нельзя объединять в один заказ блюда разных `DiningLocation`.

---

# 11. Pickup Slots

Пример:

```text
12:15–12:30
12:30–12:45
12:45–13:00
```

У каждого slot есть:

```text
capacity
reserved_count
```

Если:

```text
reserved_count >= capacity
```

слот становится недоступным.

Также необходимо учитывать:

```text
working hours
+
preparation buffer
+
slot capacity
```

---

# 12. Серверная валидация заказа

Перед созданием заказа backend проверяет:

```text
✓ location существует
✓ location активна
✓ location принимает заказы
✓ item существует
✓ item доступен
✓ quantity > 0
✓ pickup slot существует
✓ pickup slot принадлежит location
✓ pickup slot свободен
```

Цена всегда берётся **из БД**, а не из frontend.

Создание:

```text
Order
+
OrderItems
+
Pickup reservation
```

должно выполняться транзакционно.

---

# 13. REST API

## Customer

```http
GET /api/locations
GET /api/locations/{id}
GET /api/locations/{id}/menu
GET /api/menu-items/{id}

POST /api/orders
GET /api/orders/{publicCode}
```

### Создание заказа

```json
{
  "location_id": 1,
  "pickup_slot_id": 32,
  "customer_name": "Иван",
  "items": [
    {
      "menu_item_id": 14,
      "quantity": 2,
      "note": "Без лука"
    }
  ],
  "customer_note": ""
}
```

Ответ:

```json
{
  "order_code": "UF-1842",
  "status": "CREATED",
  "total": 17.00
}
```

---

## Staff

```http
GET /api/staff/orders
GET /api/staff/orders/{id}
PATCH /api/staff/orders/{id}/status
```

Пример:

```json
{
  "status": "PREPARING"
}
```

---

## Admin

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

---

# 14. Customer UI

Минимальная структура:

```text
/
├── locations
├── locations/{id}
├── menu-items/{id}
├── cart
├── checkout
└── orders/{code}
```

## Главные экраны

### Locations

```text
Кафе Главного корпуса
Открыто
[Открыть меню]
```

### Menu

```text
Бургеры
Напитки
Снэки

Чизбургер
8.50 BYN
[Добавить]
```

### Cart

```text
Чизбургер × 2
Картофель × 1

Итого: 21.00 BYN

Pickup:
12:15–12:30

[Оформить]
```

### Order Status

```text
UF-1842

● Заказ принят
● Приготовление
● Готов
○ Выдан
```

---

# 15. Staff UI

Основной экран должен быть похож на order board:

```text
NEW
 ├── UF-1842
 └── UF-1843

PREPARING
 └── UF-1840

READY
 └── UF-1837
```

Карточка заказа:

```text
UF-1842

2 × Чизбургер
1 × Картофель

Без лука

Pickup: 12:15–12:30

[Принять]
[Готово]
[Выдан]
```

---

# 16. Уведомления

Для MVP достаточно:

```text
Order status
+
polling
```

Например:

```http
GET /api/orders/UF-1842
```

Frontend обновляет статус каждые несколько секунд.

Позже:

```text
WebSocket
SSE
Browser Notification
Email
```

---

# 17. Бизнес-правила

### BR-01

Нельзя заказать недоступное блюдо.

### BR-02

Нельзя заказать в закрытую точку.

### BR-03

Корзина содержит товары только одной точки питания.

### BR-04

Нельзя выбрать переполненный pickup slot.

### BR-05

Цена заказа фиксируется при оформлении.

### BR-06

После `COMPLETED` заказ нельзя вернуть в `PREPARING`.

### BR-07

Все изменения статуса записываются в event history.

### BR-08

Один `public_code` соответствует одному заказу.

### BR-09

Backend является источником истины для total, availability и pickup capacity.

---

# 18. Безопасность

Даже без login необходимо защищать backend от:

* подмены цены;
* отрицательного quantity;
* заказа недоступных блюд;
* доступа к чужим заказам;
* повторного создания заказа;
* SQL injection;
* XSS;
* CSRF для административных действий;
* некорректных переходов статусов.

Особенно важно:

```text
Frontend ≠ trusted source
```

---

# 19. Рекомендуемая архитектура

Для проекта достаточно **modular monolith**:

```text
Browser
   ↓
UniFood Backend
   ├── Catalog
   ├── Cart
   ├── Orders
   ├── Pickup
   ├── Staff
   └── Admin
        │
        ├── PostgreSQL
        └── MongoDB
```

Microservices и Kubernetes для MVP не нужны.

---

# 20. Приоритет разработки

## Этап 1 — Core

```text
DiningLocation
Menu
Category
MenuItem
```

## Этап 2 — Ordering

```text
Cart
PickupSlot
Order
OrderItem
```

## Этап 3 — Fulfillment

```text
Staff Dashboard
Order Status
Status History
```

## Этап 4 — Management

```text
Admin
Menu CRUD
Availability
Pickup slots
```

## Этап 5 — Optional

```text
Notifications
Search
Reorder
Modifiers
Analytics
Payment
```

---

# 21. Минимальный MVP

Чтобы система уже была полноценной, MVP должен позволять пройти весь путь:

```text
User
 ↓
Location
 ↓
Menu
 ↓
Dish
 ↓
Cart
 ↓
Pickup Slot
 ↓
Order UF-XXXX
 ↓
Staff
 ↓
Preparing
 ↓
Ready
 ↓
Completed
```

Если этот pipeline работает устойчиво, основная бизнес-задача UniFood решена.

---

# 22. Что считать результатом исследования GET

Из GET мы берём прежде всего **модель бизнес-процесса**, а не закрытый код:

```text
merchant
menu
ordering
pickup
order management
preparation status
notifications
```

Reverse-engineering проекты (`cbord-api`, `get-app-apple-wallet` и исследования GET Mobile) полезны как технический материал о клиент/backend взаимодействии, но **не являются полной современной спецификацией GET Food**.

Поэтому архитектура UniFood должна считаться самостоятельным проектным решением, основанным на наблюдаемом поведении campus food ordering.

---

# 23. Основные источники

* CBORD — *The Next Generation of Mobile Food Ordering with GET*.
* CBORD — *GET Order Manager*.
* CBORD Support — merchant ordering configuration.
* GET Guest Ordering pages университетов.
* University of Minnesota Duluth — GET Food.
* Hope College — GET Mobile.
* `mattorib/cbord-api`.
* `Mcrich23/get-app-apple-wallet`.
* Jason He — reverse engineering CBORD GET Mobile.
* ShareBRB / GET App reverse engineering.

---

# 24. Главное решение для UniFood

**Центральной сущностью системы является `Order`, а не `User`.**

Архитектура:

```text
DiningLocation
      ↓
     Menu
      ↓
  MenuItem
      ↓
     Cart
      ↓
  PickupSlot
      ↓
    Order
      ↓
Order Status
      ↓
    Pickup
```

Без login, без campus account и без delivery это остаётся достаточно компактной системой, но уже представляет собой реальный campus food-ordering продукт, а не простой CRUD-каталог.

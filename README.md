# users-api

REST API справочника пользователей на Laravel 12. Витринный проект: Sanctum, Policies, FormRequest, API Resources, SQLite, тесты.

## Зачем этот проект

Не «ещё один CRUD», а способ показать, как я работаю с Laravel: где держу права, как защищаю поля от повышения прав, что возвращает API и чем это подтверждено. Три решения, которые считаю здесь главными, разобраны в конце — их и стоит читать в коде.

## Стек

- PHP 8.5, Laravel 12
- SQLite
- Laravel Sanctum — токены
- PHPUnit — feature-тесты

## Запуск

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

## Демо-доступ

Сид создаёт двух пользователей, пароль у обоих `password`:

| email | роль |
|---|---|
| `admin@example.com` | админ |
| `user@example.com` | обычный пользователь |

Токены намеренно не печатаются в вывод сида — открытый токен существует только в момент создания и светить его в терминале/скриншотах не нужно. Выписать токен:

```bash
php artisan tinker --execute="echo \App\Models\User::where('email','admin@example.com')->first()->createToken('demo')->plainTextToken;"
```

> В БД хранится только хеш токена. После `migrate:fresh` старые токены перестают работать — выписывайте заново.

## Примеры запросов

Все запросы требуют `-H "Accept: application/json"`. Без него Laravel отвечает редиректом на несуществующую страницу логина вместо JSON-401. Замени `<TOKEN>` на токен из команды выше.

```bash
# Текущий пользователь — кто я и админ ли
curl -s \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  http://127.0.0.1:8000/api/user

# Список: пагинация по 15, поиск ?search= по name и email
curl -s \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  http://127.0.0.1:8000/api/users

# Показать одного пользователя
curl -s \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  http://127.0.0.1:8000/api/users/1

# Создать (только админ) → 201
curl -s -X POST \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -d '{"name":"Ivan","email":"ivan@example.com","password":"***"}' \
  http://127.0.0.1:8000/api/users

# Обновить частично → 200
curl -s -X PATCH \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -d '{"name":"Ivan Petrov"}' \
  http://127.0.0.1:8000/api/users/2

# Удалить (только админ, не себя) → 204
curl -s -X DELETE \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  http://127.0.0.1:8000/api/users/3

# Без токена → 401
curl -s \
  -H "Accept: application/json" \
  http://127.0.0.1:8000/api/users
```

## Маршруты

`routes/api.php` — `apiResource` под `auth:sanctum`, ровно пять маршрутов.

| Метод | Путь | Действие |
|---|---|---|
| GET | `/api/users` | список, пагинация 15, `?search=` по name/email |
| POST | `/api/users` | создание → 201 |
| GET | `/api/users/{user}` | показать |
| PUT / PATCH | `/api/users/{user}` | обновить |
| DELETE | `/api/users/{user}` | удалить → 204 |

## Права

Единственное место, где решается доступ — `app/Policies/UserPolicy.php`. Контроллеры прав не содержат, только вызывают политику; у create/update авторизацию вызывает FormRequest.

| Действие | Обычный пользователь | Админ |
|---|---|---|
| список | ✅ | ✅ |
| показать | только себя | любого |
| создать | ❌ 403 | ✅ |
| обновить | только себя | любого |
| удалить | ❌ 403 | любого, **кроме себя** |

## Структура

```
app/
  Http/Controllers/UserController.php   authorize → запрос к базе → ресурс
  Http/Requests/StoreUserRequest.php    правила + authorize() через политику
  Http/Requests/UpdateUserRequest.php   sometimes, unique с ignore
  Http/Resources/UserResource.php       единственная точка «что наружу»
  Models/User.php                       casts hashed/boolean, дефолт is_admin
  Policies/UserPolicy.php               все правила доступа
database/
  factories/UserFactory.php             состояние admin() для тестов и сида
  seeders/DatabaseSeeder.php            два демо-пользователя
tests/Feature/UserApiTest.php           13 feature-тестов
```

## Три решения, которые стоит читать в коде

**Почему 403 на чужих данных, а не 404.** `GET /users/{other}` возвращает 403: ресурс существует, но тебе нельзя. Несуществующий id отдаёт 404 сам implicit binding. Скрывать разницу и врать клиенту «такого нет» — плохая услуга отладке; права — это то, что API умеет различать, и он их различает.

**Почему `is_admin` нельзя повысить через API.** Поля нет в правилах валидации, а контроллер пишет только `$request->validated()` — лишнее поле из тела запроса до записи не доходит. Второй слой — `#[Fillable(['name', 'email', 'password'])]`, третий — каст `boolean` и дефолт модели, чтобы новый пользователь никогда не отдавал `is_admin: null`. Роль меняется сидером или консолью, но не через HTTP. Тест: `test_is_admin_cannot_be_escalated_through_the_api`.

**Почему удаление себя — отдельный флоу.** В политике `delete` требует `is_admin && ! $user->is($model)`: админ не может удалить сам себя. Дело не в правах, а в целостности — один случайный DELETE мог оставить пустую таблицу без админа. Осознанное удаление аккаунта — это процесс (подтверждение, второй админ, каскад связанных данных), а не один запрос.

## Тесты

```bash
php artisan test
```

13 feature-тестов (`tests/Feature/UserApiTest.php`) проверяют API через границу, которую он отдаёт наружу: 401 без токена, отсутствие `password` в ответе, 403 на чужих данных, права админа, 422 на невалидном email, хеширование пароля, запрет эскалации `is_admin`, запрет самоудаления. Тестовая база — SQLite в памяти (`phpunit.xml`), боевая база не затрагивается.

Форматирование:

```bash
vendor/bin/pint
```

## Лицензия

MIT

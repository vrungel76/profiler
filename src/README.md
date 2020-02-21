
# Профилирование кода

Для того, чтобы включить профилирование необходимо:

* [установить расширение php для профилирования](#установка-расширения)
* [установить тул для сбора и просмотра результатов профайлинга](#установка-xhgui)
* добавить подгрузку профилировщика в настройки сервера или index.php
    * [для докера простой способ](#ещё-один-вариант-для-докера)

## Что это и зачем

* `xhprof` - расширение php для профелирования работы скриптов
* `xhgui` - интерфейс для просмотра работы профайлера

## xhprof профайлер

Профайлер работает по запросу, для того, чтобы запустить профайлер необходимо вызвать функцию `xhprof_enable()`, чтобы
получить результат профайлинга `xhprof_disable()`. Все вызовы мужду `xhprof_enable` и `xhprof_disable` будут добавлены
в результат профайлинга.

```php
xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
// do something
// ...
$profileResult = xhprof_disable();
```

В `$profileResult` попадёт массив с полной информацией о вызовах функций/методов: сколько в каждой из функций было
потрачено процессорного веремни, сколько времени было затрачено, сколько памяти было использовано etc.

В массив смотреть не интересно, поэтому можно складывать данные в базу, а потом брать их оттуда для отображения. 
Профайлер собирает информацию о вызовах функций в процессе исполнения и после завершения выполения скрипта скидывает
собранную информацию в базу данных. Результ работы профайлера можно посмотреть в GUI.


## Интерфейс xhGUI
Если гуй запущен в докер контейнере по умолчанию, то он находится по адресу `http://localhost:8142`.

В общем и целом выглядит это как-то так:
![Главная страница xhGUI][xhgui]

[xhgui]: assets/xhgui/xhgui-recent.png ""

Если кликнуть по времени создания запроса, то откроется страница с подробным описанием того, чем занимался php
в процессе обработки запроса.

![Результат профилирования запроса][profile-main]

[profile-main]: assets/xhgui/xhgui-profile.png

Если перемотать ниже, то можно увидеть огромную портянку с информацией о вызовах функций

![Список вызовов функций][profile-main-table]

[profile-main-table]: assets/xhgui/xhgui-profile-2.png

Кликнув по имени функции/метода открывается страница с подробным описанием вызовов конкретно этой
функции, и ссылками откуда она была вызвана и какие вызовы совершала

![Информация о вызове][profile2]

[profile2]: assets/xhgui/xhgui-profile-3.png "Информация о вызове"

[↑ вверх](#профилирование-кода)

## Информация о вызовах

Колонки в таблице вызовов:
* Call Count - общее количество вызовов метода/функции
* Self Wall Time - время проведённое интерпретатором внутри функции, без учёта вызовов из этой функции. Время учитывается в том числе и проведённое в процессе ожидания ответа от БД например
* Self CPU - время процессора, потраченное внутри функции, без учёта вызовов из этой функции. Чистые затраты процессорного времени, без учёта ожидания результатов из базы, вызовов sleep и т.п.
* Self Memory Usage - сколько памяти было выделено внутри функции
* Self Peak Memory Usage - сколько памяти реально выделил php, пока был внутри функции
* Inclusive Wall Time - общее время проведённое внутри функции
* Inclusive CPU - общее время процессора потраченное внутри функции
* Inclusive Memory Usage - сколько всего памяти было использовано (может быть отрицательным, если внутри функции произошла очистка памяти)
* Inclusive Peak Memory Usage - сколько всего памяти было выделено интерпретатором

[↑ вверх](#профилирование-кода)

## Установка расширения в докер проекта

Для php 7 в Dockerfile (где-то после debug):

```bash
# Install PECL and PEAR extensions
RUN pecl install \
    mongodb

RUN docker-php-ext-enable \
    mongodb

# profiler install start
RUN mkdir -p /usr/src/php/ext/tideways_xhprof
RUN wget -qO- https://github.com/tideways/php-xhprof-extension/archive/v5.0.2.tar.gz | tar xz --strip-components=1 -C /usr/src/php/ext/tideways_xhprof
RUN docker-php-ext-install tideways_xhprof
# profiler install end
```

Теперь проект сможет профилирвоать и скидывать все в монгу.

[↑ вверх](#профилирование-кода)

## Установка xhgui

Клонируем репозиторий с тулзой для просмотра результатов профилирования в корень проекта (туда где лежит docker-compose.yml проекта) в папку например xhgui
```bash
git clone https://github.com/perftools/xhgui.git xhgui/
```

Идем в папку `config`, копируем `config.default.php` в `config.php`.
По умолчанию профайлер запускается для 1% рандомных запросов, для изменения этого поведения правим настройку:
```php
    // ...
    'profiler.enable' => function() {
        return rand(1, 100) === 42;
    },
    // ...
```
заменяем на:
```php
    // ...
    'profiler.enable' => function() {
        return true;
    },
    // ...
```


[↑ вверх](#профилирование-кода)

## Запуск профилировщика 

Для того, что бы проект начал писать данные профайлинга, необходимо добавить пару пакетов.
В настройки композера в dev зависимости добавляем библиотеку агрегации данных профайлера и коннектор для монги
```json
...
  "require-dev": {
    "perftools/xhgui-collector": "^1.7.0",
    "alcaeus/mongo-php-adapter": "^1.1"
  },
...
```
Так как сам xhgui запускается в отдельном контейнере и просто слушает 27017 порт хост машины, нужно немного подправить
код `index.php` для того, чтобы агрегатор даных профилировщика мог достучаться до монги и отправить в неё данные.

```php
// проверяем установлено ли в окружение значение хоста для монги
// это показатель что монга запущена для приема данных 
if (getenv('XHGUI_MONGO_HOST')) {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    // говорим, где лежат конфиги xhgui 
    define('XHGUI_CONFIG_DIR', __DIR__ . '/../xhgui/config/');
    // запускаем профилирование
    include __DIR__ . '/../vendor/perftools/xhgui-collector/external/header.php';
}
```


[↑ вверх](#профилирование-кода)

### Запуск xhgui 

В корне проекта рядышком с `docker-compose.yml` кладём ещё один файл `docker-compose.profiler.yml` с содержанием
```yaml
version: '3.1'
services:
  # необходимо убедиться, что имя сервиса для php-fpm называется 'php'
  # если имя сервиса не такое-же, то агрегатор логов профайлера не найдёт монгу и положит туда данные для xhgui
  # поэтому проверяем что в проектк сервис php-fpm назыывается php а так же проеряем что
  # остальные названия сервисов не пересекаются с называниями сервисов в проекте  
  php:
    environment:
      - XHGUI_MONGO_HOST=mongodb://mongo:27017

  app:
    container_name: xhgui-app
    build: ./xhgui
    volumes:
      - ./xhgui/webroot:/var/www/xhgui/webroot
      - ./xhgui/config:/var/www/xhgui/config
    environment:
      - XHGUI_MONGO_HOST=mongodb://mongo:27017
      - XHGUI_MONGO_DATABASE=xhprof

  nginx:
    container_name: xhgui-web
    image: nginx:1
    volumes:
      - ./xhgui/nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - ./xhgui/webroot:/var/www/xhgui/webroot
    ports:
      - "8142:80"

  mongo:
    container_name: xhgui-mongo
    image: percona/percona-server-mongodb:3.6
    # (case sensitive) engine: mmapv1, rocksdb, wiredTiger, inMemory
    command: --storageEngine=wiredTiger
    environment:
      - MONGO_INITDB_DATABASE=xhprof
    volumes:
      - ./xhgui/mongo.init.d:/docker-entrypoint-initdb.d
    expose:
      - "27017"
```

Запуск контейнера осуществляем командой
```bash
docker-compose -f docker-compose.yml -f docker-compose.profiler.yml up -d
```
либо без указания конфига для докер композа, если профилировщик не нужен
```bash
docker-compose up -d
```

После этого всё должно взлететь.
Если всё прошло успешно, запустится два контейнера, один с проектом, второй с GUI профайлера, приложение будет
доступно по адресу указанному в `docker-compose.yml`, а gui профайлера по адресу `http://localhost:8142`


## Если не работает

В принципе, установка xhgui не должна вызывать проблем и надеюсь он запустился и работает.

Куда смотреть, если при обновлении страницы с результатами профайлинга `http://localhost:8142` пусто:

* зайти в докер контейнер с `php-fpm` и проверить установлены ли расширения `mongodb` и `xhprof`
```bash
php -i | grep mongo
php -i | grep xhprof
```
Если вывод пуст, то установить недостающее расширение, либо включить его в настройках `php` 
* зайти в конфиг `xhgui-collector` и проверить, точно ли `profiler.enable` возвращает `true`, проверить что он вообще инклудится
* проверить в `index.php` точно ли инклудится `header.php` коллектора профайлера
* посмотреть, правильный ли хост `mongo` установлен в переменную окружения `XHGUI_MONGO_HOST`

[↑ вверх](#профилирование-кода)

## Если не хочется профилировать всё

Логика подключения профайлера по умолчанию подразумевает, что профилируется всё. Если такое поведение не требуется, то
можно скопировать куда то рядом с `index.php` файл [Profiler.php](Profiler.php) и переписать в `index.php` так:
```php
if (getenv('XHGUI_MONGO_HOST')) {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    // путь до папки с конфигурацией профайлера
    define('XHGUI_CONFIG_DIR', __DIR__ . '/../xhgui/config/');
    // путь до папки с коллектором профилирования
    define('XHPROF_COLLECTOR_DIR', '/../vendors/perftools/xhgui-collector/');
    require_once __DIR__ . 'Profiler.php';
}
```

После этого можно будет включать профайлер по требованию и он будет отправлять результаты в xhgui:
```php
\Profiler::start();
// ... do something interesting
\Profiler::stop();
```

Если же захочется снова профилировать всё, то после подгрузки класса профайлера добавлдяем вызов `Profiler::start`:
```php
if (getenv('XHGUI_MONGO_HOST')) {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    // путь до папки с конфигурацией профайлера
    define('XHGUI_CONFIG_DIR', __DIR__ . '/../xhgui/config/');
    // путь до папки с коллектором профилирования
    define('XHPROF_COLLECTOR_DIR', '/../vendors/perftools/xhgui-collector/');
    require_once __DIR__ . 'Profiler.php';
    Profiler::start();
}
```

[↑ вверх](#профилирование-кода)

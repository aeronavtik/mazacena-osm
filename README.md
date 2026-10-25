# MazaCena OSM export

Ежедневная выгрузка из OpenStreetMap для карты магазинов mazacena.lv (плагин DLE «MazaCena Stores»):
продуктовые магазины и торговые центры, аптеки и промтовары, ориентиры (станции, автовокзалы, рынки, думы,
больницы, почта, церкви, музеи) — только Латвия, обрезка по официальной границе.

Каждую ночь (00:15 UTC) GitHub Actions:

1. скачивает свежую выгрузку Латвии с [Geofabrik](https://download.geofabrik.de/europe/latvia.html) (~100 МБ);
2. вырезает Латвию по границе (`ISO3166-1=LV`) и оставляет нужные объекты утилитой [osmium](https://osmcode.org/osmium-tool/);
3. `build.php` пишет файлы в формате ответа Overpass API;
4. публикует их на GitHub Pages:

| Файл | Что внутри |
|---|---|
| `meta.json` | время выгрузки, дата данных OSM, число объектов |
| `food.json` | `shop=supermarket, convenience, wholesale, general, department_store, mall` |
| `other.json` | `amenity=pharmacy`, `shop=doityourself, hardware, furniture, variety_store, chemist, cosmetics…` |
| `places.json` | ориентиры с названием |

Если объектов подозрительно мало, выгрузка не публикуется, и на Pages остаются вчерашние файлы.

## Настройка (один раз)

1. Создайте на GitHub **публичный** репозиторий (например, `mazacena-osm`) и загрузите в него содержимое этой папки,
   включая `.github/`.
2. **Settings → Pages → Build and deployment → Source: GitHub Actions.**
3. **Actions → OSM export → Run workflow** — первый запуск вручную (около 2–3 минут).
4. Проверьте, что открывается `https://<пользователь>.github.io/mazacena-osm/meta.json`.
5. В админке сайта: «Карта магазинов» → «Крон и защита от сбоев» → «Адрес выгрузки OSM» —
   `https://<пользователь>.github.io/mazacena-osm/`.

Публичный репозиторий нужен для бесплатных GitHub Pages и минут Actions. В нём нет ничего секретного:
только эти скрипты и открытые данные OSM.

Чтобы GitHub не отключил расписание через 60 дней без коммитов, задача раз в 50 дней делает пустой служебный коммит.

Данные: © участники OpenStreetMap, лицензия [ODbL](https://www.openstreetmap.org/copyright).

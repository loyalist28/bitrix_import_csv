# Скрипт импорта элементов инфоблока 1C-Bitrix из csv-файла.

Файл импорта должен располагаться в папке **/dev-import**.

Основные параметры импорта задаются в переменных:<br>
- **$importFile** - csv-файл импортируемых элементов.<br>
- **$iBlockId** - ID инфоблока, куда импортируем элементы.<br>
- **$fieldAssocArr** - массив ассоциаций полей инфоблока и ключей полей импортируемых данных.<br>
- **$propsAssocArr** - массив ассоциаций кодов свойств инфоблока и ключей полей импортируемых данных.

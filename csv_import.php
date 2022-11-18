<?php
use \Bitrix\Main\Loader;

set_time_limit(0);

define('NO_KEEP_STATISTICS', true);
define("NO_AGENT_CHECK", true);
define('NOT_CHECK_PERMISSIONS', true);

// Установка запущен ли скрипт из CLI
if(PHP_SAPI == 'cli') {
    $cliRun = true;

    define('BX_BUFFER_USED', true);
} else {
    $cliRun = false;
}

// Если скрипт запущен из CLI, и $_SERVER['DOCUMENT_ROOT'] отсутствует, 
// то потребовать передать его третьим параметром запуска скрипта.
if($cliRun) {
    if(empty($_SERVER['DOCUMENT_ROOT'])) {
        if(!isset($argv[2])) {
            exit('Ошибка: требуется передать третьим параметром запуска $_SERVER["DOCUMENT_ROOT"] для запускаемого скрипта');
        }
        $_SERVER['DOCUMENT_ROOT'] = $argv[3];
    }
}

$documentRoot = $_SERVER['DOCUMENT_ROOT'];

require_once($documentRoot . '/bitrix/modules/main/include/prolog_before.php');
if($cliRun) {
    while (ob_get_level()) {
        ob_end_flush();
    }
}

Loader::IncludeModule('iblock');

$importFile = '';
$iBlockId = false;
if($cliRun) {
    if(!isset($argv[1])) {
        exit('Ошибка: не указан файл импорта.');
    } else {
        $importFile = $documentRoot . '/' . $argv[1];
    }
    if(!isset($argv[2])) {
        exit('Ошибка: не указан ID инфоблока.');
    } else {
        $iBlockId = intval($argv[2]);
    }
} else {
    if(empty($_GET['i_file'])) {
        exit('<h3>Ошибка: не указан файл импорта</h3>');
    } else {
        $importFile = $documentRoot . '/' . $_GET['i_file'];
    }
    if(empty($_GET['ib_id'])) {
        exit('<h3>Ошибка: не указан ID инфоблока</h3>');
    } else {
        $iBlockId = intval($_GET['ib_id']);
    }
}
$fieldsAssocArr = array(
    'XML_ID' => 'id',
    'NAME' => 'name',
    'PREVIEW_TEXT' => 'preview_text',
    'DETAIL_TEXT' => 'detail_text'
);
$propsAssocArr = array(
    'PROPERTY_1' => 'prop1',
    'PROPERTY_2' => 'prop2'
);

if(($importElementsArr = parse_csv_to_import_arr($importFile, $fieldsAssocArr, $propsAssocArr)) !== false) {
    if(!empty($importElementsArr)) {
        $result = array('added' => [], 'add_faults' => [], 'updated' => [], 'update_faults' => []);
        $iBlockElement = new CIBlockElement;
        $propsSelectArr = array();
        foreach(array_keys($propsAssocArr) as $propertyCode) {
            $propsSelectArr[] = "PROPERTY_$propertyCode"; 
        }
        $selectArr = array_merge(['ID', 'IBLOCK_ID'], array_keys($fieldsAssocArr), $propsSelectArr);
    
        foreach($importElementsArr as $importElement) {
            $queryResult = CIBlockElement::GetList([], ['IBLOCK_ID' => $iBlockId, 'XML_ID' => $importElement['XML_ID']], false, false, $selectArr);
            
            // Если элемент существует, то проверить на изменения данных
            // и обновить, если изменения есть.
            if($resultRow = $queryResult->Fetch()) {
                $elementId = $resultRow['ID'];
                $fieldsUpdateArr = array();
                $propertyValues = array();

                foreach($resultRow as $field => $value) {
                    if(empty($propertyValues)) {
                        if(preg_match("/^PROPERTY_(.+)_VALUE$/", $field, $matches)) {
                            if($value != $importElement[$matches[1]]) {
                                foreach(array_keys($propsAssocArr) as $propertyCode) {
                                    $propertyValues[$propertyCode] = $importElement[$propertyCode];
                                }

                                $fieldsUpdateArr['PROPERTY_VALUES'] = $propertyValues;
                            }
                        }
                    }
                    if(key_exists($field, $importElement)) {
                        if($value != $importElement[$field]) {
                            $fieldsUpdateArr[$field] = $importElement[$field];
                        }
                    }
                }
    
                if(!empty($fieldsUpdateArr)) {
                    if($iBlockElement->Update($elementId, $fieldsUpdateArr)) {
                        $result['updated'][$importElement['XML_ID']] = $elementId;
                    } else {
                        $result['update_faults'][$importElement['XML_ID']] = $iBlockElement->LAST_ERROR;
                    }
                }
            } else {
                // Если элемент не существует, то добавить.
                $propertyValues = array();
                foreach(array_keys($propsAssocArr) as $propertyCode) {
                    $propertyValues[$propertyCode] = $importElement[$propertyCode];
                }
                
                $loadElementFields = Array(
                    'ACTIVE' => 'Y',
                    'IBLOCK_ID' => $iBlockId,
                    'PROPERTY_VALUES' => $propertyValues
                );
                foreach(array_keys($fieldsAssocArr) as $field) {
                    $loadElementFields[$field] = $importElement[$field];
                }
                
                if($newElementId = $iBlockElement->Add($loadElementFields)) {
                    $result['added'][$importElement['XML_ID']] = $newElementId;   
                } else {
                    $result['add_faults'][$importElement['XML_ID']] = $iBlockElement->LAST_ERROR;
                }
            }
        }
    }
} else {
    exit();
}
// Запуск в браузере
if(!$cliRun) : ?>
<div id="import-block">
    <style>
        #import-block {
            border: 2px solid black;
            border-radius: 5px;
            padding: 10px;
            padding-top: 0;
        }
        .report-block {
            border-bottom: 2px solid black;
            padding: 10px 0;
        }
        .report-block:first-child {
            border-top: 2px solid black;
            border-bottom: 2px solid black;
            padding: 10px 0;
        }
        .report-block .description {
            display: flex;
            justify-content: space-between;
        }
        .report-block ul {
            display: none;
            padding-left: 28px;
        }
        .toggler {
            color: #0E3FB8;
            text-decoration: underline;
            cursor: pointer;
        }
        h1, h3 {
            margin: 10px 0;
        }
    </style>

    <h1>Импорт элементов в инфоблок</h1>
    <p>
        Источник: <b><?= $importFile ?></b><br>
        Разделитель ячеек: <b>;</b><br>
    </p>
    <h3>Отчет по импорту:</h3>

    <div id="import-results">

    <?php if(!empty($result)) : ?>
        <?php if(!empty($result['added'])) : ?>

        <div id="added-elements" class="report-block">
            <div class='description'>
                <span>Элементов импортировано: <b><?= count($result['added']) ?></b>.</span> <span class="toggler show">Показать</span>
            </div>
            <ul class="elements-list">
            <?php 
            foreach($result['added'] as $externCode => $addedId) {
                echo "<li>Элемент с внешним кодом $externCode. ID нового элемента инфоблока: $addedId</li>"; 
            } 
            ?>
            </ul>
        </div>

        <?php endif;
        if(!empty($result['add_faults'])) : ?>

        <div id="add-faults" class="report-block">
            <div class="description">
                <span>Не удалось импортировать элементов: <b><?= count($result['add_faults']) ?></b>.</span> <span class="toggler show">Показать</span>
            </div>
            <ul class="elements-list">
            <?php 
            foreach($result['add_faults'] as $externCode => $errorText) {
                echo "<li>Элемент с внешним кодом $externCode.<br><b>Ошибка:</b> $errorText</li>";
            } 
            ?>
            </ul>
        </div>

        <?php endif;
        if(!empty($result['updated'])) : ?>

        <div id="updated-elements" class="report-block">
            <div class="description">
                <span>Элементов обновлено: <b><?= count($result['updated']) ?></b>.</span> <span class="toggler show">Показать</span>
            </div>
            <ul class="elements-list">
            <?php 
            foreach($result['updated'] as $externCode => $elementId) {
                echo "<li>Элемент с внешним кодом $externCode. ID элемента: $elementId</li>"; 
            } 
            ?>
            </ul>
        </div>

        <?php endif; 
        if(!empty($result['update_faults'])) : ?>

        <div id="update-faults" class="report-block">
            <div class="description">
                <span>Не удалось обновить элементов: <b><?= count($result['update_faults']) ?></b>.</span> <span class="toggler show">Показать</span>
            </div>
            <ul class="elements-list">
            <?php 
            foreach($result['update_faults'] as $externCode => $errorText) {
                echo "<li>Элемент с внешним кодом $externCode.<br><b>Ошибка:</b> $errorText</li>";
            } 
            ?>
            </ul>
        </div>

        <?php endif;
    else :
        echo 'Нет элементов для импорта и обновления';
    endif;
    ?>
    </div>
    <script>
        document.getElementById('import-results').addEventListener('click', function(event) {      
            if(event.target.classList.contains('toggler')) {
                const toggler = event.target;
                const elementsList = toggler.closest('.report-block').querySelector('.elements-list');

                if(toggler.classList.contains('show')) {
                    elementsList.style.display = 'block';
                    toggler.classList = 'toggler hide';
                    toggler.innerText = 'Скрыть';
                } else if(toggler.classList.contains('hide')) {
                    elementsList.style.display = 'none';
                    toggler.classList = 'toggler show';
                    toggler.innerText = 'Показать';
                }
            }
        }, true);
    </script>
</div>
<?
// Запуск в CLI
else:
    echo 'Импорт элементов в инфоблок' . PHP_EOL;
    echo 'Источник: ' . $importFile  . PHP_EOL;
    echo 'Разделитель ячеек: ;'  . PHP_EOL . PHP_EOL;
    echo '== Отчет по импорту ==' . PHP_EOL;

    if(!empty($result)) {
        $stdin = fopen('php://stdin', 'r');
        $detailOrderItems = array();

        if(!empty($result['added'])) {
            echo 'Элементов импортировано: ' . count($result['added']) . PHP_EOL;
            $detailOrderItems[] = 'added'; 
        }
        if(!empty($result['add_faults'])) {
            echo 'Не удалось импортировать элементов: ' . count($result['add_faults']) . PHP_EOL;
            $detailOrderItems[] = 'add_faults';
        }
        if(!empty($result['updated'])) {
            echo 'Элементов обновлено: ' . count($result['updated']) . PHP_EOL;
            $detailOrderItems[] = 'updated'; 
        }
        if(!empty($result['update_faults'])) {
            echo 'Не удалось обновить элементов: ' . count($result['update_faults']) . PHP_EOL;
            $detailOrderItems[] = 'update_faults'; 
        }

        echo PHP_EOL . 'Показать детальный отчет? (y / n)' . PHP_EOL;

        while(($input = strtolower(trim(fgets($stdin), PHP_EOL))) != 'n') {
            if($input != 'y') {
                echo PHP_EOL . 'Некорректный ввод!' . PHP_EOL;
                echo PHP_EOL . 'Показать детальный отчет? (y / n)' . PHP_EOL;
            } else {
                print_order_items_list($detailOrderItems);

                $lastListItemNumber = count($detailOrderItems) + 1;

                while(($itemNumber = trim(fgets($stdin), PHP_EOL)) != $lastListItemNumber) {
                    if(!is_numeric($itemNumber) || $itemNumber > $lastListItemNumber) {
                        echo PHP_EOL . 'Указан неверный номер пункта!' . PHP_EOL;
    
                        print_order_items_list($detailOrderItems);
                    } else {
                        $choosenItem = $detailOrderItems[$itemNumber - 1];
                        $strTemplate = '';
                        switch ($choosenItem) {
                            case 'added': 
                                $strTemplate = '- Элемент с внешним кодом %d. ID нового элемента инфоблока: %d' . PHP_EOL;
                                echo PHP_EOL . "Импортированые элементы:" . PHP_EOL;
            
                                break;
                            case 'add_faults': 
                                $strTemplate = '- Элемент с внешним кодом %d.' . PHP_EOL . 'Ошибка: %s';
                                echo PHP_EOL . "Не удалось импортировать:" . PHP_EOL;
            
                                break;
                            case 'updated': 
                                $strTemplate = '- Элемент с внешним кодом %d. ID элемента: %d' . PHP_EOL;
                                echo PHP_EOL . "Обновленные элементы:" . PHP_EOL; 
            
                                break;
                            case 'update_faults': 
                                $strTemplate = '- Элемент с внешним кодом %d.' . PHP_EOL . 'Ошибка: %s';
                                echo PHP_EOL . "Не удалось обновить:" . PHP_EOL;
            
                                break;
                        }
            
                        echo str_repeat('-', 30) . PHP_EOL;
                        foreach($result[$choosenItem] as $key => $value) {
                            $value = str_replace('<br>', PHP_EOL, $value);
                            
                            printf($strTemplate, $key, $value);
                        }
                        echo str_repeat('-', 30) . PHP_EOL;
                        
                        print_order_items_list($detailOrderItems);
                    }
                }
                
                break;
            }
        } 
    } else {
        echo 'Нет элементов для импорта и обновления';
    }
endif;

/**
 * Преобразует csv-файл импорта в массив элементов для импорта в инфоблок. 
 * 
 * @param   string  $importFile      Путь у csv-файлу импорта. 
 * @param   array   $fieldsAssocArr  Массив ассоциаций ключей полей данных файла импорта с полями инфоблока.
 * @param   array   $propsAssocArr   Массив ассоциаций ключей полей данных файла импорта с кодами свойств инфоблока.
 * 
 * @return  array|false Массив элементов для импорта в инфоблок. false в случае ошибки отрытия файла.
 */
function parse_csv_to_import_arr($importFile, $fieldsAssocArr, $propsAssocArr) {
    $handle = fopen($importFile, 'r');

    if($handle) {
        $keysRow = true;
        $importElementsArr = array();
        $importKeys = array();
        $importKeysCount = 0;

        while($importDataRow = fgetcsv($handle, null, ';')) {
            // Если выбраная строка файла пустая, то выбрать следующую 
            if(is_null($importDataRow[0])) {
                continue;
            }
            // Если последний элемент в строке данных пустой из-за разделителя данных в конце,
            // то удалить его 
            if(empty($importDataRow[count($importDataRow) - 1])) {
                array_pop($importDataRow);
            }
            
            if($keysRow) {
                $importKeys = $importDataRow;
                
                $keysRow = false;
                $importKeysCount = count($importKeys);
            } else {
                // Если количество ключей полей данных больше чем количество полей данных в строке,
                // то дополнить недостащие поля данных пустыми строками.
                if($importKeysCount > count($importDataRow)) {
                    $importDataRow = array_pad($importDataRow, $importKeysCount, '');
                } else if($importKeysCount < count($importDataRow)) {
                    // Если количество ключей полей данных меньше чем количество полей данных в строке,
                    // то обрезать строку данных с конца до длины равной количеству ключей полей данных.
                    $importDataRow = array_slice($importDataRow, 0, $importKeysCount);
                }

                $rawImportElement = array_combine($importKeys, $importDataRow);
                $importElement = array();
    
                foreach(array_merge($fieldsAssocArr, $propsAssocArr) as $importField => $rawImportField) {
                    $importElement[$importField] = $rawImportElement[$rawImportField];
                }
    
                $importElementsArr[] = $importElement;
            }
        }
    
        fclose($handle);
        
        return $importElementsArr;
    } else {
        echo '<h3>Ошибка: невозможно открыть файл ' . $importFile . '</h3>';

        return false;
    }
}
/**
 * Выводит список пунктов для детального показа отчета по импорту при запуске скрипта из CLI. 
 * 
 * @param   array  $detailOrderItems   Массив с пунктами отчета
 */
function print_order_items_list($detailOrderItems) {
    echo PHP_EOL . 'Выберите пункт для детализации отчета: ' . PHP_EOL;

    foreach($detailOrderItems as $i => $item) {
        $itemNum = $i + 1;
        
        switch($item) {
            case 'added':
                echo "{$itemNum}. Импортированые элементы" . PHP_EOL;

                break;
            case 'add_faults':
                echo "{$itemNum}. Не удалось импортировать" . PHP_EOL;

                break;
            case 'updated':
                echo "{$itemNum}. Обновленные элементы" . PHP_EOL; 

                break;
            case 'update_faults':
                echo "{$itemNum}. Не удалось обновить" . PHP_EOL;

                break;
        }
    }

    echo (count($detailOrderItems) + 1) . '. Завершить работу' . PHP_EOL;
    echo PHP_EOL . 'Введите номер пункта: ';
}

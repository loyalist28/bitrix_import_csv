<?php 
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

CModule::IncludeModule('iblock');

$importFile = $_SERVER['DOCUMENT_ROOT'] . '/dev-import/test.csv';
$iBlockId = 5;
$iBlockName = ((CIBlock::GetByID($iBlockId))->GetNext())['NAME'];
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
        $result = array();
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
    } else {
        echo 'Нет элементов для импорта и обновления';
    }
} else {
    echo 'Ошибка: не удалось выполнить импорт';
}
?>
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

    <h1>Импорт элементов в инфоблок "<?= $iBlockName ?>"</h1>
    <p>
        Источник: <b><?= $importFile ?></b><br>
        Разделитель ячеек: <b>;</b><br>
    </p>
    <h3>Отчет по импорту:</h3>

    <div id="import-results">
<?php if(!empty($result)) : ?>
    <?php if(isset($result['added'])) : ?>
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
    if(isset($result['add_faults'])) : ?>
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
    if(isset($result['updated'])) : ?>
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
    if(isset($result['update_faults'])) : ?>
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
        echo 'Ошибка: невозможно открыть файл ' . $importFile;

        return false;
    }
}
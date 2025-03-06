<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2017-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Familia;
use FacturaScripts\Dinamic\Model\LineaTransferenciaStock;
use FacturaScripts\Dinamic\Model\TransferenciaStock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Cristo M. Estévez Hernández  <cristom.estevez@gmail.com>
 * @author Carlos García Gómez          <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez     <hola@danielfg.es>
 */
class EditTransferenciaStock extends EditController
{
    public function getFamilySelect(): array
    {
        $families = [];

        $familyModel = new Familia();
        foreach ($familyModel->codeModelAll() as $family) {
            $families[] = [
                'value' => $family->code,
                'description' => $family->description
            ];
        }

        return $families;
    }

    public function getModelClassName(): string
    {
        return 'TransferenciaStock';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'warehouse';
        $data['title'] = 'stock-transfer';
        $data['icon'] = 'fas fa-exchange-alt';
        return $data;
    }

    protected function addLineAction(): array
    {
        // permisos
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return ['addLine' => false];
        }

        // obtenemos datos del formulario
        $code = $this->request->get('code');
        $barcode = $this->request->request->get('codbarras');
        $ref = $this->request->request->get('referencia');
        if (empty($code) || (empty($barcode) && empty($ref))) {
            return ['addLine' => false];
        }

        // buscamos la referencia
        $variante = new Variante();
        $where = empty($barcode) ?
            [new DataBaseWhere('referencia', $ref)] :
            [new DataBaseWhere('codbarras', $barcode)];
        if (false === $variante->loadFromCode('', $where)) {
            Tools::log()->warning('no-data');
            return ['addLine' => false];
        }

        // cargamos la transferencia
        $transferencia = new TransferenciaStock();
        if (false === $transferencia->loadFromCode($code)) {
            return ['addLine' => false];
        }

        // añadimos la línea
        $newLine = $transferencia->addLine($variante->referencia, $variante->idproducto, 1);
        if (empty($newLine->primaryColumnValue())) {
            Tools::log()->error('record-save-error');
            return ['addLine' => false];
        }

        Tools::log()->notice('record-updated-correctly');
        return ['addLine' => true];
    }

    protected function autocompleteProductAction(): array
    {
        $list = [];
        $variante = new Variante();
        $query = (string)$this->request->get('term');
        foreach ($variante->codeModelSearch($query, 'referencia') as $value) {
            $list[] = [
                'key' => Tools::fixHtml($value->code),
                'value' => Tools::fixHtml($value->description)
            ];
        }

        if (empty($list)) {
            $list[] = ['key' => null, 'value' => Tools::lang()->trans('no-data')];
        }

        return [
            'autocompleteProduct' => true,
            'list' => $list,
        ];
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');
        $this->createViewsLines();
    }

    protected function createViewsLines(string $viewName = 'EditTransferenciaStockLines'): void
    {
        $this->addHtmlView($viewName, $viewName, 'LineaTransferenciaStock', 'lines', 'fas fa-list');
    }

    protected function deleteLineAction(): array
    {
        if (false === $this->permissions->allowDelete) {
            Tools::log()->warning('not-allowed-delete');
            return ['deleteLine' => false];
        }

        // cargamos la transferencia
        $transferencia = new TransferenciaStock();
        if (false === $transferencia->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return ['deleteLine' => false];
        }

        $lineaTransferencia = new LineaTransferenciaStock();
        $idLinea = $this->request->get('idlinea');
        if (false === $lineaTransferencia->loadFromCode($idLinea)) {
            Tools::log()->warning('record-not-found');
            return ['deleteLine' => false];
        }

        if ($lineaTransferencia->idtrans !== $transferencia->idtrans) {
            Tools::log()->warning('line-not-belong-to-count');
            return ['deleteLine' => false];
        }

        if ($lineaTransferencia->delete()) {
            Tools::log()->notice('record-deleted-correctly');
            return ['deleteLine' => true];
        }

        Tools::log()->error('record-deleted-error');
        return ['deleteLine' => false];
    }

    /**
     * @param string $action
     *
     * @return bool
     */
    protected function execPreviousAction($action)
    {
        if ((bool)$this->request->get('ajax', false)) {
            $this->setTemplate(false);

            switch ($action) {
                case 'addLine':
                    $data = $this->addLineAction();
                    break;

                case 'autocomplete-product':
                    $data = $this->autocompleteProductAction();
                    break;

                case 'deleteLine':
                    $data = $this->deleteLineAction();
                    break;

                case 'preloadProduct':
                    $data = $this->preloadProductAction();
                    break;

                case 'renderLines':
                    $data = $this->getRenderLines();
                    break;

                case 'updateLine':
                    $data = $this->updateLineAction();
                    break;
            }

            $content = array_merge(
                ['messages' => $this->getMessages()],
                $data ?? []
            );
            $this->response->setContent(json_encode($content));
            return false;
        }

        switch ($action) {
            case 'transfer-stock':
                return $this->transferStockAction();

            default:
                return parent::execPreviousAction($action);
        }
    }

    protected function getMessages(): array
    {
        $logLevels = ['critical', 'error', 'info', 'notice', 'warning'];
        return Tools::log()->read('master', $logLevels);
    }

    protected function getRenderLines(): array
    {
        // permisos
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return [
                'renderLines' => false,
                'count' => 0,
                'html' => $this->getRenderLinesTable([], []),
            ];
        }

        // cargamos la transferencia
        $transferencia = new TransferenciaStock();
        if (false === $transferencia->loadFromCode($this->request->get('code'))) {
            return [
                'renderLines' => false,
                'count' => 0,
                'html' => $this->getRenderLinesTable([], []),
            ];
        }

        // obtenemos las líneas
        $lines = $transferencia->getLines(['idlinea' => 'DESC']);

        // si no hay líneas, terminamos
        if (empty($lines)) {
            return [
                'renderLines' => true,
                'count' => 0,
                'html' => $this->getRenderLinesTable([], []),
            ];
        }

        $tableHead = [
            '<th>' . Tools::lang()->trans('reference') . '</th>',
            '<th class="text-center" style="width: 15%;">' . Tools::lang()->trans('quantity') . '</th>',
            '<th class="text-right">' . Tools::lang()->trans('user') . '</th>',
            '<th class="text-right">' . Tools::lang()->trans('date') . '</th>',
        ];

        $resultHead = $this->pipe('renderLinesTableHead', $tableHead, $transferencia);
        if (is_array($resultHead)) {
            $tableHead = $resultHead;
        }

        // recorremos las líneas de la transferencia
        $tableBody = [];
        foreach ($lines as $line) {
            $dataLine = [];
            $product = $line->getProducto();

            $dataLine[] = '<td class="align-middle">'
                . '<a href="EditProducto?code=' . $line->idproducto . '" target="_blank">' . $line->referencia . '</a>'
                . '<div class="small">' . Tools::textBreak($product->descripcion) . '</div>'
                . '</td>';

            if ($transferencia->completed) {
                $dataLine[] = '<td class="text-center align-middle">'
                    . '<input type="number" name="cantidad" id="lineaCantidad' . $line->idlinea . '" class="form-control text-center qty-line" value="' . $line->cantidad . '"/>'
                    . '</td>';
            } else {
                $dataLine[] = '<td class="text-center align-middle">'
                    . '<div class="input-group">'
                    . '<div class="input-group-prepend">'
                    . '<button class="btn btn-outline-danger delete-line btn-spin-action" title="'
                    . Tools::lang()->trans('delete') . '" onclick="deleteLine(\'' . $line->idlinea . '\')"><i class="fas fa-trash-alt"></i></button>'
                    . '</div>'
                    . '<input type="number" name="cantidad" id="lineaCantidad' . $line->idlinea . '" class="form-control text-center qty-line" value="' . $line->cantidad . '"/>'
                    . '<div class="input-group-append">'
                    . '<button class="btn btn-info btn-update-line btn-spin-action" type="button" onclick="updateLine(\''
                    . $line->idlinea . '\')" title="' . Tools::lang()->trans('update') . '"><i class="fas fa-save"></i></button>'
                    . '</div>'
                    . '</div>'
                    . '</td>';
            }

            $dataLine[] = '<td class="text-right align-middle">'
                . $line->nick
                . '</td>';

            $dataLine[] = '<td class="text-right align-middle">'
                . Tools::dateTime($line->fecha)
                . '</td>';

            $resultDataLine = $this->pipe('renderLinesTableBodyLine', $dataLine, $line, $transferencia);
            if (is_array($resultDataLine)) {
                $dataLine = $resultDataLine;
            }

            $tableBody[$line->idlinea] = $dataLine;
        }

        return [
            'renderLines' => true,
            'count' => count($lines),
            'html' => $this->getRenderLinesTable($tableHead, $tableBody),
        ];
    }

    protected function getRenderLinesTable(array $tableHead, array $tableBody): string
    {
        if (empty($tableHead) || empty($tableBody)) {
            return '';
        }

        $html = '<thead>'
            . '<tr>'
            . implode('', $tableHead)
            . '</tr>'
            . '</thead>'
            . '<tbody>';

        foreach ($tableBody as $idlinea => $line) {
            $html .= '<tr data-idlinea="' . $idlinea . '">'
                . implode('', $line)
                . '</tr>';
        }

        return $html . '</tbody>';
    }

    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();
        parent::loadData($viewName, $view);

        if ($viewName === $mvn && $view->model->completed) {
            $this->setSettings($viewName, 'btnSave', false);
            $this->setSettings($viewName, 'btnUndo', false);
        }
    }

    protected function preloadProductAction(): array
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return ['preloadProduct' => false];
        }

        // cargamos la transferencia
        $transferencia = new TransferenciaStock();
        if (false === $transferencia->loadFromCode($this->request->get('code'))) {
            return ['preloadProduct' => false];
        }

        // obtenemos los datos
        $codfamilia = $this->request->get('family');
        $option = $this->request->get('option', 'one');

        // obtenemos todas las variantes
        $sql = 'SELECT v.* from variantes as v';

        // si hay familia, filtramos
        if (!empty($codfamilia)) {
            $sql .= ' JOIN productos as p ON p.idproducto = v.idproducto'
                . ' WHERE p.codfamilia = ' . $this->dataBase->var2str($codfamilia)
                . ' AND v.stockfis > 0';
        } else {
            $sql .= ' WHERE v.stockfis > 0';
        }

        // obtenemos las variantes
        $variants = $this->dataBase->select($sql);

        // si no hay variantes, terminamos
        if (empty($variants)) {
            return ['preloadProduct' => true];
        }

        // recorremos las variantes
        foreach ($variants as $variant) {
            $qty = $option === 'one' ? 1 : $variant['stockfis'];
            $newLine = $transferencia->addLine($variant['referencia'], $variant['idproducto'], $qty);
            if (empty($newLine->primaryColumnValue())) {
                Tools::log()->error('record-save-error');
                return ['preloadProduct' => false];
            }
        }

        return ['preloadProduct' => true];
    }

    protected function transferStockAction(): bool
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return true;
        } elseif (false === $this->validateFormToken()) {
            return true;
        }

        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return true;
        } elseif ($model->completed) {
            return true;
        }

        if (false === $model->transferStock()) {
            Tools::log()->error('record-save-error');
            return true;
        }

        Tools::log()->notice('record-updated-correctly');
        Tools::log('audit')->info('applied-stock-transfer', ['%code%' => $model->primaryColumnValue()]);
        return true;
    }

    protected function updateLineAction(): array
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return ['updateLine' => false];
        }

        // cargamos la transferencia
        $transferencia = new TransferenciaStock();
        if (false === $transferencia->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return ['updateLine' => false];
        }

        $lineaTransferencia = new LineaTransferenciaStock();
        $idLinea = $this->request->request->get('idlinea');
        if (false === $lineaTransferencia->loadFromCode($idLinea)) {
            Tools::log()->notice('record-not-found');
            return ['updateLine' => false];
        }

        if ($lineaTransferencia->idtrans !== $transferencia->idtrans) {
            Tools::log()->warning('line-not-belong-to-count');
            return ['updateLine' => false];
        }

        $lineaTransferencia->cantidad = (float)$this->request->request->get('cantidad');

        $resultLine = $this->pipe('updateLine', $lineaTransferencia);
        if (null !== $resultLine) {
            $lineaTransferencia = $resultLine;
        }

        if (false === $lineaTransferencia->save()) {
            Tools::log()->error('record-save-error');
            return ['updateLine' => false];
        }

        Tools::log()->notice('record-updated-correctly');
        return ['updateLine' => true];
    }
}

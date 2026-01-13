<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\Familia;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\LineaConteoStockTraza;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Carlos Garcia Gomez          <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez     <hola@danielfg.es>
 */
class EditConteoStock extends EditController
{
    public function getFamilySelect(): array
    {
        $families = [];
        foreach (Familia::all() as $family) {
            $families[] = [
                'value' => $family->id(),
                'description' => $family->descripcion
            ];
        }
        return $families;
    }

    public function getModelClassName(): string
    {
        return 'ConteoStock';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'warehouse';
        $data['title'] = 'stock-count';
        $data['icon'] = 'fa-solid fa-scroll';
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

        // buscamos la variante
        $variante = new Variante();
        $where = empty($barcode) ?
            [Where::column('referencia', $ref)] :
            [Where::column('codbarras', $barcode)];
        if (false === $variante->loadWhere($where)) {
            Tools::log()->warning('no-data');
            return ['addLine' => false];
        }

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->load($code)) {
            return ['addLine' => false];
        }

        // añadimos la línea
        $newLine = $conteo->addLine($variante->referencia, $variante->idproducto, 1);
        if (empty($newLine->id())) {
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
            $list[] = ['key' => null, 'value' => Tools::trans('no-data')];
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

    protected function createViewsLines(string $viewName = 'EditConteoStockLines'): void
    {
        $this->addHtmlView($viewName, $viewName, 'LineaConteoStock', 'lines', 'fa-solid fa-list');
    }

    protected function deleteLineAction(): array
    {
        if (false === $this->permissions->allowDelete) {
            Tools::log()->warning('not-allowed-delete');
            return ['deleteLine' => false];
        }

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->load($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return ['deleteLine' => false];
        }

        $lineaConteo = new LineaConteoStock();
        $idLinea = $this->request->get('idlinea');
        if (false === $lineaConteo->load($idLinea)) {
            Tools::log()->warning('record-not-found');
            return ['deleteLine' => false];
        }

        if ($lineaConteo->idconteo !== $conteo->idconteo) {
            Tools::log()->warning('line-not-belong-to-count');
            return ['deleteLine' => false];
        }

        if ($lineaConteo->delete()) {
            Tools::log()->notice('record-deleted-correctly');
            return ['deleteLine' => true];
        }

        Tools::log()->error('record-deleted-error');
        return ['deleteLine' => false];
    }

    protected function exportAction()
    {
        if (false === $this->views[$this->active]->settings['btnPrint'] ||
            false === $this->permissions->allowExport) {
            Tools::log()->warning('no-print-permission');
            return;
        }

        $this->setTemplate(false);
        $this->exportManager->newDoc(
            $this->request->get('option', ''),
            $this->title,
            (int)$this->request->request->get('idformat', ''),
            $this->request->request->get('langcode', '')
        );

        foreach ($this->views as $selectedView) {
            if (false === $selectedView->settings['active']) {
                continue;
            }

            if ($selectedView->getViewName() === 'EditConteoStockLines') {
                $lines = [];
                $where = [Where::column('idconteo', $this->views[$this->active]->model->id())];
                foreach (LineaConteoStock::all($where) as $line) {
                    $lines[] = [
                        Tools::trans('reference') => $line->referencia,
                        Tools::trans('quantity') => $line->cantidad,
                        Tools::trans('date') => $line->fecha,
                    ];
                }

                if (empty($lines)) {
                    continue;
                }

                $this->exportManager->addTablePage(array_keys($lines[0]), $lines, [], Tools::trans('lines'));

                if (Plugins::isEnabled('Trazabilidad')) {
                    $lotes = [];
                    foreach (LineaConteoStockTraza::all($where) as $lineTraza) {
                        $lote = $lineTraza->getLote();
                        $line = $lineTraza->getCountingLine();
                        $lotes[] = [
                            Tools::trans('reference') => $line->referencia,
                            Tools::trans('batch-serial-number') => $lote->numserie,
                            Tools::trans('quantity') => $lineTraza->quantity,
                            Tools::trans('date') => $lineTraza->last_update,
                        ];
                    }

                    if (empty($lotes)) {
                        continue;
                    }

                    $this->exportManager->addTablePage(array_keys($lotes[0]), $lotes, [], Tools::trans('traceability'));
                }

                continue;
            }

            $codes = $this->request->request->getArray('codes');
            if (false === $selectedView->export($this->exportManager, $codes)) {
                break;
            }
        }

        $this->exportManager->show($this->response);
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

        return match ($action) {
            'update-stock' => $this->updateStockAction(),
            default => parent::execPreviousAction($action),
        };
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

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->load($this->request->get('code'))) {
            return [
                'renderLines' => false,
                'count' => 0,
                'html' => $this->getRenderLinesTable([], []),
            ];
        }

        // obtenemos las líneas
        $lines = $conteo->getLines(['idlinea' => 'DESC']);

        // si no hay líneas, terminamos
        if (empty($lines)) {
            return [
                'renderLines' => true,
                'count' => 0,
                'html' => $this->getRenderLinesTable([], []),
            ];
        }

        $tableHead = [
            '<th>' . Tools::trans('reference') . '</th>',
            '<th class="text-center" style="width: 15%;">' . Tools::trans('quantity') . '</th>',
            '<th class="text-end">' . Tools::trans('user') . '</th>',
            '<th class="text-end">' . Tools::trans('date') . '</th>',
        ];

        $resultHead = $this->pipe('renderLinesTableHead', $tableHead, $conteo);
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

            if ($conteo->completed) {
                $dataLine[] = '<td class="text-center align-middle">'
                    . '<input type="number" name="cantidad" id="lineaCantidad' . $line->idlinea . '" class="form-control text-center qty-line" value="' . $line->cantidad . '"/>'
                    . '</td>';
            } else {
                $dataLine[] = '<td class="text-center align-middle">'
                    . '<div class="input-group">'
                    . '<button class="btn btn-outline-danger delete-line btn-spin-action" title="'
                    . Tools::trans('delete') . '" onclick="deleteLine(\'' . $line->idlinea . '\')"><i class="fa-solid fa-trash-alt"></i></button>'
                    . '<input type="number" name="cantidad" id="lineaCantidad' . $line->idlinea . '" class="form-control text-center qty-line" value="' . $line->cantidad . '"/>'
                    . '<button class="btn btn-info btn-update-line btn-spin-action" type="button" onclick="updateLine(\''
                    . $line->idlinea . '\')" title="' . Tools::trans('update') . '"><i class="fa-solid fa-save"></i></button>'
                    . '</div>'
                    . '</td>';
            }

            $dataLine[] = '<td class="text-end align-middle">'
                . $line->nick
                . '</td>';

            $dataLine[] = '<td class="text-end align-middle">'
                . Tools::dateTime($line->fecha)
                . '</td>';

            $resultDataLine = $this->pipe('renderLinesTableBodyLine', $dataLine, $line, $conteo);
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

        // si el modelo está completado, bloqueamos la edición
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

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->load($this->request->get('code'))) {
            return ['preloadProduct' => false];
        }

        // obtenemos los datos
        $codfamilia = $this->request->get('family');
        $option = $this->request->get('option', 'cero');

        // obtenemos todas las variantes
        $sql = 'SELECT v.referencia, v.idproducto, COALESCE(s.cantidad, 0) AS cantidad '
            . ' FROM variantes as v'
            . ' JOIN productos as p ON p.idproducto = v.idproducto'
            . ' LEFT JOIN stocks as s ON s.referencia = v.referencia AND s.codalmacen = ' . $this->dataBase->var2str($conteo->codalmacen)
            . ' WHERE p.nostock = 0';

        // si hay familia, filtramos
        if (!empty($codfamilia)) {
            $sql .= ' p.codfamilia = ' . $this->dataBase->var2str($codfamilia);
        }

        // obtenemos las variantes
        $variants = $this->dataBase->select($sql);

        // si no hay variantes, terminamos
        if (empty($variants)) {
            return ['preloadProduct' => true];
        }

        // recorremos las variantes
        foreach ($variants as $variant) {
            $qty = $option === 'cero' ? 0.0 : (float)$variant['cantidad'];
            $newLine = $conteo->addLine($variant['referencia'], $variant['idproducto'], $qty);
            if (empty($newLine->id())) {
                Tools::log()->error('record-save-error');
                return ['preloadProduct' => false];
            }
        }

        return ['preloadProduct' => true];
    }

    protected function updateLineAction(): array
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return ['updateLine' => false];
        }

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->load($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return ['updateLine' => false];
        }

        $lineaConteo = new LineaConteoStock();
        $idLinea = $this->request->request->get('idlinea');
        if (false === $lineaConteo->load($idLinea)) {
            Tools::log()->notice('record-not-found');
            return ['updateLine' => false];
        }

        if ($lineaConteo->idconteo !== $conteo->idconteo) {
            Tools::log()->warning('line-not-belong-to-count');
            return ['deleteLine' => false];
        }

        $lineaConteo->cantidad = (float)$this->request->request->get('cantidad');

        $resultLine = $this->pipe('updateLine', $lineaConteo);
        if (null !== $resultLine) {
            $lineaConteo = $resultLine;
        }

        if (false === $lineaConteo->save()) {
            Tools::log()->error('record-save-error');
            return ['updateLine' => false];
        }

        Tools::log()->notice('record-updated-correctly');
        return ['updateLine' => true];
    }

    protected function updateStockAction(): bool
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return true;
        } elseif (false === $this->validateFormToken()) {
            return true;
        }

        $model = $this->getModel();
        if (false === $model->load($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return true;
        } elseif ($model->completed) {
            return true;
        }

        if (false === $model->updateStock()) {
            Tools::log()->error('record-save-error');
            return true;
        }

        Tools::log()->notice('record-updated-correctly');
        Tools::log('audit')->info('applied-stock-count', ['%code%' => $model->id()]);
        return true;
    }
}

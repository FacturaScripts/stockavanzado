<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\Familia;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Description of EditConteoStock
 *
 * @author Carlos Garcia Gomez          <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez     <hola@danielfg.es>
 */
class EditConteoStock extends EditController
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
        return 'ConteoStock';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'warehouse';
        $data['title'] = 'stock-count';
        $data['icon'] = 'fas fa-scroll';
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

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->loadFromCode($code)) {
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

        // comprobamos si ya existe la línea
        $newLine = new LineaConteoStock();
        $where2 = [
            new DataBaseWhere('idconteo', $conteo->idconteo),
            new DataBaseWhere('referencia', $variante->referencia)
        ];
        if (false === $newLine->loadFromCode('', $where2)) {
            $newLine->cantidad = 0.0;
            $newLine->idconteo = $conteo->idconteo;
            $newLine->idproducto = $variante->idproducto;
            $newLine->referencia = $variante->referencia;
        } else {
            $newLine->cantidad++;
        }

        // guardamos la línea
        $newLine->fecha = Tools::dateTime();
        $newLine->nick = $this->user->nick;
        if (false === $newLine->save()) {
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

    protected function createViewsLines(string $viewName = 'EditConteoStockLines')
    {
        $this->addHtmlView($viewName, $viewName, 'LineaConteoStock', 'lines', 'fas fa-list');
    }

    protected function deleteLineAction(): array
    {
        if (false === $this->permissions->allowDelete) {
            Tools::log()->warning('not-allowed-delete');
            return ['deleteLine' => false];
        }

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return ['deleteLine' => false];
        }

        $lineaConteo = new LineaConteoStock();
        $idLinea = $this->request->get('idlinea');
        if (false === $lineaConteo->loadFromCode($idLinea)) {
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
            case 'update-stock':
                return $this->updateStockAction();

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
        $html = '';

        // permisos
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return [
                'renderLines' => false,
                'count' => 0,
                'html' => $html,
            ];
        }

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->loadFromCode($this->request->get('code'))) {
            return [
                'renderLines' => false,
                'count' => 0,
                'html' => $html,
            ];
        }

        // obtenemos las líneas
        $lines = $conteo->getLines();

        // si no hay líneas, terminamos
        if (empty($lines)) {
            $html = '<tr class="table-warning">'
                . '<td colspan="5">' . Tools::lang()->trans('no-data') . '</td>'
                . '</tr>';

            return [
                'renderLines' => true,
                'count' => 0,
                'html' => $html,
            ];
        }

        // recorremos las líneas del conteo
        foreach ($lines as $line) {
            $html .= '<tr data-idlinea="' . $line->idlinea . '">'
                . '<td class="align-middle"><a href="EditProducto?code=' . $line->idproducto . '" target="_blank">' . $line->referencia . '</a></td>'
                . '<td class="text-right align-middle"><div class="input-group">'
                . '<input type="number" id="lineaCantidad' . $line->idlinea . '" class="form-control text-right qty-line" value="' . $line->cantidad . '"/>'
                . '<div class="input-group-append"><button class="btn btn-outline-info btn-update-line btn-spin-action" type="button" onclick="updateLine(\''
                . $line->idlinea . '\')" title="' . Tools::lang()->trans('update') . '"><i class="fas fa-save"></i></button></div></td>'
                . '<td class="text-right align-middle">' . $line->nick . '</td>'
                . '<td class="text-right align-middle">' . Tools::dateTime($line->fecha) . '</td>'
                . '<td class="text-right align-middle"><button class="btn btn-danger btn-sm delete-line btn-spin-action" title="'
                . Tools::lang()->trans('delete') . '" onclick="deleteLine(\'' . $line->idlinea . '\')"><i class="fas fa-trash-alt"></i></button></td>'
                . '</tr>';
        }

        return [
            'renderLines' => true,
            'count' => count($lines),
            'html' => $html,
        ];
    }

    protected function preloadProductAction(): array
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return ['preloadProduct' => false];
        }

        // cargamos el conteo
        $conteo = new ConteoStock();
        if (false === $conteo->loadFromCode($this->request->get('code'))) {
            return ['preloadProduct' => false];
        }

        // obtenemos los datos
        $codfamilia = $this->request->get('family');
        $option = $this->request->get('option', 'cero');

        // obtenemos todas las variantes
        $sql = 'SELECT v.* from variantes as v';

        // si hay familia, filtramos
        if (!empty($codfamilia)) {
            $sql .= ' JOIN productos as p ON p.idproducto = v.idproducto'
                . ' WHERE p.codfamilia = ' . $this->dataBase->var2str($codfamilia);
        }

        // obtenemos las variantes
        $variants = $this->dataBase->select($sql);

        // si no hay variantes, terminamos
        if (empty($variants)) {
            return ['preloadProduct' => true];
        }

        // recorremos las variantes
        foreach ($variants as $variant) {
            // comprobamos si ya existe la línea
            $newLine = new LineaConteoStock();
            $where2 = [
                new DataBaseWhere('idconteo', $conteo->idconteo),
                new DataBaseWhere('referencia', $variant['referencia'])
            ];
            if (false === $newLine->loadFromCode('', $where2)) {
                $newLine->cantidad = $option === 'cero' ? 0.0 : $variant['stockfis'];
                $newLine->idconteo = $conteo->idconteo;
                $newLine->idproducto = $variant['idproducto'];
                $newLine->referencia = $variant['referencia'];
            } else {
                $newLine->cantidad++;
            }

            // guardamos la línea
            $newLine->fecha = Tools::dateTime();
            $newLine->nick = $this->user->nick;
            if (false === $newLine->save()) {
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
        if (false === $conteo->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return ['updateLine' => false];
        }

        $lineaConteo = new LineaConteoStock();
        $idLinea = $this->request->request->get('idlinea');
        if (false === $lineaConteo->loadFromCode($idLinea)) {
            Tools::log()->notice('record-not-found');
            return ['updateLine' => false];
        }

        if ($lineaConteo->idconteo !== $conteo->idconteo) {
            Tools::log()->warning('line-not-belong-to-count');
            return ['deleteLine' => false];
        }

        $lineaConteo->cantidad = (float)$this->request->request->get('cantidad');

        if ($this->user->nick !== $lineaConteo->nick) {
            $lineaConteo->fecha = Tools::dateTime();
            $lineaConteo->nick = $this->user->nick;
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
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return true;
        }

        if (false === $model->updateStock()) {
            Tools::log()->error('record-save-error');
            return true;
        }

        Tools::log()->notice('record-updated-correctly');
        Tools::log('audit')->info('applied-stock-count', ['%code%' => $model->primaryColumnValue()]);
        return true;
    }
}

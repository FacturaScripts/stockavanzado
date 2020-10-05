<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Lib\ExtendedController\BaseView;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\StockAvanzado\Lib\StockRebuild;
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaConteoStock;

/**
 * Description of EditConteoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class EditConteoStock extends EditController
{

    /**
     * 
     * @return string
     */
    public function getModelClassName()
    {
        return 'ConteoStock';
    }

    /**
     * 
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'warehouse';
        $data['title'] = 'stock-count';
        $data['icon'] = 'fas fa-scroll';
        $data['showonmenu'] = false;
        return $data;
    }

    /**
     * 
     * @return bool
     */
    protected function addLineAction()
    {
        if (false === $this->permissions->allowUpdate) {
            $this->toolBox()->i18nLog()->warning('not-allowed-update');
            return true;
        }

        $code = $this->request->get('code');
        $barcode = $this->request->request->get('codbarras');
        $ref = $this->request->request->get('referencia');
        if (empty($code) || (empty($barcode) && empty($ref))) {
            return true;
        }

        $conteo = new ConteoStock();
        if (false === $conteo->loadFromCode($code)) {
            return true;
        }

        $variante = new Variante();
        $where = empty($barcode) ? [new DataBaseWhere('referencia', $ref)] : [new DataBaseWhere('codbarras', $barcode)];
        if (false === $variante->loadFromCode('', $where)) {
            $this->toolBox()->i18nLog()->warning('no-data');
            return true;
        }

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
        }

        $newLine->cantidad++;
        $newLine->fecha = \date(LineaConteoStock::DATETIME_STYLE);
        $newLine->nick = $this->user->nick;
        if (false === $newLine->save()) {
            $this->toolBox()->i18nLog()->error('record-save-error');
            return true;
        }

        $this->toolBox()->i18nLog()->notice('record-updated-correctly');
        return true;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');
        $this->createViewsLines();
    }

    /**
     * 
     * @param string $viewName
     */
    protected function createViewsLines(string $viewName = 'lines')
    {
        $this->addHtmlView($viewName, 'EditConteoStockLines', 'LineaConteoStock', 'lines', 'fas fa-list');
    }

    /**
     * 
     * @return bool
     */
    protected function deleteLineAction()
    {
        if (false === $this->permissions->allowDelete) {
            $this->toolBox()->i18nLog()->warning('not-allowed-delete');
            return true;
        }

        $lineaConteo = new LineaConteoStock();
        $idlinea = $this->request->request->get('idlinea');
        if ($lineaConteo->loadFromCode($idlinea) && $lineaConteo->delete()) {
            $this->toolBox()->i18nLog()->notice('record-deleted-correctly');
            return true;
        }

        $this->toolBox()->i18nLog()->error('record-deleted-error');
        return true;
    }

    /**
     * 
     * @return bool
     */
    protected function editLineAction()
    {
        if (false === $this->permissions->allowUpdate) {
            $this->toolBox()->i18nLog()->warning('not-allowed-update');
            return true;
        }

        $lineaConteo = new LineaConteoStock();
        $idlinea = $this->request->request->get('idlinea');
        if (false === $lineaConteo->loadFromCode($idlinea)) {
            $this->toolBox()->i18nLog()->notice('record-not-found');
            return true;
        }

        $lineaConteo->cantidad = (float) $this->request->request->get('quantity');
        $lineaConteo->fecha = \date(LineaConteoStock::DATETIME_STYLE);
        $lineaConteo->nick = $this->user->nick;
        if (false === $lineaConteo->save()) {
            $this->toolBox()->i18nLog()->error('record-save-error');
            return true;
        }

        $this->toolBox()->i18nLog()->notice('record-updated-correctly');
        return true;
    }

    /**
     * 
     * @param string $action
     *
     * @return bool
     */
    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'add-line':
                return $this->addLineAction();

            case 'delete-line':
                return $this->deleteLineAction();

            case 'edit-line':
                return $this->editLineAction();

            case 'rebuild-stock':
                return $this->rebuildStockAction();

            default:
                return parent::execPreviousAction($action);
        }
    }

    /**
     * 
     * @param string   $viewName
     * @param BaseView $view
     */
    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();

        switch ($viewName) {
            case 'lines':
                $where = [new DataBaseWhere('idconteo', $this->getViewModelValue($mvn, 'idconteo'))];
                $view->cursor = $view->model->all($where, ['fecha' => 'DESC'], 0, 0);
                $view->count = $view->model->count($where);
                break;

            case $mvn:
                parent::loadData($viewName, $view);
                if (empty($view->model->nick)) {
                    $view->model->nick = $this->user->nick;
                }
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }

    /**
     * 
     * @return bool
     */
    protected function rebuildStockAction()
    {
        StockRebuild::rebuild();
        $this->toolBox()->i18nLog()->notice('record-updated-correctly');
        return true;
    }
}

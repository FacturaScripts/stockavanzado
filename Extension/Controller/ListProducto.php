<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\StockRebuildManager;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\WorkEvent;

/**
 * Description of ListProducto
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ListProducto
{
    protected function createViews(): Closure
    {
        return function () {
            if ($this->user->admin || ($this->user->can('EditProducto', 'update') && $this->user->level >= 30)) {
                $this->addButton('ListStock', [
                    'action' => 'rebuild-stock',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-wand-magic-sparkles',
                    'label' => 'rebuild-stock'
                ]);
            }
        };
    }

    protected function execAfterAction(): Closure
    {
        return function ($action) {
            if ($action === 'rebuild-stock') {
                $this->rebuildStockAction();
            }
        };
    }

    protected function rebuildStockAction(): Closure
    {
        return function () {
            if (MovimientoStock::count() === 0) {
                Tools::log()->warning('no-movements-to-rebuild-stock');
                return;
            }

            // en este punto, $this->views['ListStock']->where ya está poblado
            // por el flujo normal (processFormData + loadData se ejecutaron antes de execAfterAction)
            $whereSql = Where::multiSqlLegacy($this->views['ListStock']->where);

            $from = 'stocks'
                . ' LEFT JOIN variantes ON variantes.referencia = stocks.referencia'
                . ' LEFT JOIN productos ON productos.idproducto = variantes.idproducto';

            $db = new DataBase();

            $total = (int)$this->request->get('total', -1);
            if ($total < 0) {
                $rows = $db->select('SELECT COUNT(DISTINCT stocks.idproducto) as total FROM ' . $from . $whereSql);
                $total = (int)($rows[0]['total'] ?? 0);
            }

            if (empty($total)) {
                Tools::log()->warning('no-products');
                return;
            }

            $offset = (int)$this->request->get('offset', 0);
            $rows = $db->select(
                'SELECT DISTINCT stocks.idproducto FROM ' . $from . $whereSql
                    . ' ORDER BY stocks.idproducto LIMIT 1 OFFSET ' . $offset
            );

            if (empty($rows)) {
                Tools::log()->info('rebuilding-stock-finished', ['%total%' => $total]);
                return;
            }

            $product = new Producto();
            if (false === $product->load((int)$rows[0]['idproducto'])) {
                Tools::log()->warning('product-not-found', ['%id%' => $rows[0]['idproducto']]);
                return;
            }

            // si hay reconstrucción de movimientos en curso, no reconstruimos el stock
            $where = [
                Where::eq('done', false),
                Where::in('name', ['Model.Producto.rebuildStockMovements', 'Model.Producto.updateStockMovements']),
                Where::eq('value', (string)$product->id())
            ];

            if (WorkEvent::count($where) > 0) {
                Tools::log()->warning('wait-stock-movements-rebuild');
                return;
            }

            StockRebuildManager::rebuild($product->id());
            Tools::log()->info('rebuilding-stock', ['%reference%' => $product->referencia, '%offset%' => $offset + 1, '%total%' => $total]);
            $this->redirect('?activetab=ListStock&action=rebuild-stock&total=' . $total . '&offset=' . ($offset + 1), 1);
        };
    }
}

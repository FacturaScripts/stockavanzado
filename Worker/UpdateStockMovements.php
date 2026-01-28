<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Worker;

use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\StockAvanzado\Lib\StockRebuildManager;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;

class UpdateStockMovements extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        // cargamos el producto
        $product = Producto::find($event->value);
        if (empty($product)) {
            return $this->done();
        }

        // recorremos todas las variantes
        foreach ($product->getVariants() as $variant) {
            // recorremos los almacenes
            foreach (Almacenes::all() as $almacen) {
                $saldo = 0;

                // recorremos los movimientos de stock
                $where = [
                    Where::eq('codalmacen', $almacen->codalmacen),
                    Where::eq('referencia', $variant->referencia)
                ];
                $orderBy = ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC'];
                $offset = 0;
                $limit = 100;

                do {
                    $movements = MovimientoStock::all($where, $orderBy, $offset, $limit);
                    foreach ($movements as $movement) {
                        // si es un conteo de stock, reiniciamos el saldo
                        if ($movement->docmodel === 'ConteoStock') {
                            $saldo = $movement->saldo;
                            continue;
                        }

                        // actualizamos el saldo acumulado
                        $saldo += $movement->cantidad;

                        // actualizamos el saldo en el movimiento, si es necesario
                        if ($movement->saldo != $saldo) {
                            $movement->saldo = $saldo;
                            $movement->save();
                        }
                    }

                    // incrementamos el offset para la siguiente iteración
                    $offset += $limit;
                } while (count($movements) > 0);
            }
        }

        $rebuild = (bool)Tools::settings('default', 'stock_rebuild', false) === true ?? false;
        if ($rebuild) {
            StockRebuildManager::rebuild($product->idproducto);
        }

        return $this->done();
    }
}

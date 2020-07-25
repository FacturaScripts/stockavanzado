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
namespace FacturaScripts\Plugins\StockAvanzado\Lib;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\ToolBox;
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Core\Model\Base\TransformerDocument;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;

/**
 * Description of StockMovementManager
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class StockMovementManager
{

    public static function rebuild()
    {
        $models = [
            new AlbaranProveedor(), new FacturaProveedor(),
            new AlbaranCliente(), new FacturaCliente()
        ];
        foreach ($models as $model) {
            foreach ($model->all([], ['fecha' => 'DESC'], 0, 1000) as $doc) {
                foreach ($doc->getLines() as $line) {
                    if (empty($line->referencia) || $line->getProducto()->nostock) {
                        continue;
                    }

                    $prevData['actualizastock'] = $line->actualizastock;
                    static::updateLine($line, $prevData, $doc);
                }
            }
        }
    }

    /**
     * 
     * @param BusinessDocumentLine $line
     * @param array                $prevData
     * @param TransformerDocument  $doc
     */
    public static function updateLine($line, $prevData, $doc)
    {
        if (false === \in_array($line->actualizastock, [1, -1], true) &&
            false === \in_array($prevData['actualizastock'], [1, -1], true)) {
            return;
        }

        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $doc->codalmacen),
            new DataBaseWhere('docid', $doc->primaryColumnValue()),
            new DataBaseWhere('docmodel', $doc->modelClassName()),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if (false === $movement->loadFromCode('', $where)) {
            $movement->codalmacen = $doc->codalmacen;
            $movement->docid = $doc->primaryColumnValue();
            $movement->docmodel = $doc->modelClassName();
            $movement->idproducto = $line->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($line->cantidad)) {
                return;
            }
        }

        $movement->cantidad = $line->actualizastock * $line->cantidad;
        $movement->documento = static::toolBox()->i18n()->trans($doc->modelClassName()) . ' ' . $doc->codigo;
        $movement->fecha = $doc->fecha;
        $movement->hora = $doc->hora;
        empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    /**
     * 
     * @return ToolBox
     */
    protected static function toolBox()
    {
        return new ToolBox();
    }
}

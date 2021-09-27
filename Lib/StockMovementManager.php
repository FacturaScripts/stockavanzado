<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2021 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaTransferenciaStock;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\TransferenciaStock;

/**
 * Description of StockMovementManager
 *
 * @author       Carlos Garcia Gomez            <carlos@facturascripts.com>
 * @collaborator Jerónimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class StockMovementManager
{

    /**
     * @var array
     */
    private static $docStates = [];

    public static function rebuild()
    {
        // removes all movements
        $movement = new MovimientoStock();
        $movement->deleteAll();

        // saves movements from business documents
        $models = [new AlbaranProveedor(), new FacturaProveedor(), new AlbaranCliente(), new FacturaCliente()];
        foreach ($models as $model) {
            // to prevent memory issues we set limit to 1000 items
            foreach ($model->all([], ['fecha' => 'DESC'], 0, 1000) as $doc) {
                // skip states that do not modify the stock
                if (static::ignoredState($doc)) {
                    continue;
                }

                foreach ($doc->getLines() as $line) {
                    // skip empty lines
                    if (empty($line->referencia)) {
                        continue;
                    }

                    // skip missing product or products without stock management
                    $product = $line->getProducto();
                    if (false === $product->exists() || $product->nostock) {
                        continue;
                    }

                    $prevData['actualizastock'] = $line->actualizastock;
                    $prevData['cantidad'] = 0.0;
                    static::updateLine($line, $prevData, $doc);
                }
            }
        }

        // save movements from transferences
        $transferenciaStock = new TransferenciaStock();

        foreach ($transferenciaStock->all([], [], 0, 0) as $transfer) {
            foreach ($transfer->getLines() as $line) {
                static::updateLineTransfer($line, $transfer);
            }
        }

        // save movements from stock counts
        $conteoStock = new ConteoStock();
        foreach ($conteoStock->all([], [], 0, 0) as $conteo) {
            foreach ($conteo->getLines() as $line) {
                static::updateLineCount($line, $conteo);
            }
        }
    }

    /**
     * @param BusinessDocumentLine $line
     * @param TransformerDocument $doc
     * @param string $fromCodalmacen
     * @param string $toCodalmacen
     */
    public static function transferLine($line, $doc, $fromCodalmacen, $toCodalmacen)
    {
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $fromCodalmacen),
            new DataBaseWhere('docid', $doc->primaryColumnValue()),
            new DataBaseWhere('docmodel', $doc->modelClassName()),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if ($movement->loadFromCode('', $where)) {
            $movement->codalmacen = $toCodalmacen;
            $movement->save();
        }
    }

    /**
     * @param BusinessDocumentLine $line
     * @param array $prevData
     * @param TransformerDocument $doc
     */
    public static function updateLine($line, $prevData, $doc)
    {
        if (false === in_array($line->actualizastock, [1, -1], true) &&
            false === in_array($prevData['actualizastock'], [1, -1], true)) {
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
            $movement->idproducto = $line->idproducto ?? $line->getProducto()->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($line->cantidad)) {
                return;
            }
        }

        $movement->cantidad -= $prevData['actualizastock'] * $prevData['cantidad'];
        $movement->cantidad += $line->actualizastock * $line->cantidad;
        $movement->documento = static::toolBox()->i18n()->trans($doc->modelClassName()) . ' ' . $doc->codigo;
        $movement->fecha = $doc->fecha;
        $movement->hora = $doc->hora;
        empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    /**
     * @param LineaConteoStock $line
     * @param ConteoStock $stockCount
     */
    public static function updateLineCount($line, $stockCount)
    {
        $docid = $stockCount->primaryColumnValue();
        $docmodel = $stockCount->modelClassName();
        $cantidad = $line->cantidad - static::getStockSum($line->referencia, $stockCount->codalmacen, $docid, $docmodel, $line->fecha);

        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $stockCount->codalmacen),
            new DataBaseWhere('docid', $docid),
            new DataBaseWhere('docmodel', $docmodel),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if (false === $movement->loadFromCode('', $where)) {
            $movement->codalmacen = $stockCount->codalmacen;
            $movement->docid = $docid;
            $movement->docmodel = $docmodel;
            $movement->idproducto = $line->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($cantidad)) {
                return true;
            }
        }

        $movement->cantidad = $cantidad;
        $movement->documento = static::toolBox()->i18n()->trans($stockCount->modelClassName()) . ' ' . $stockCount->primaryColumnValue();
        $movement->fecha = date(MovimientoStock::DATE_STYLE, strtotime($line->fecha));
        $movement->hora = date(MovimientoStock::HOUR_STYLE, strtotime($line->fecha));
        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    /**
     * @param LineaTransferenciaStock $line
     * @param TransferenciaStock $transfer
     */
    public static function updateLineTransfer($line, $transfer)
    {
        static::updateLineTransferMovement($transfer->codalmacenorigen, $line->cantidad * -1, $transfer, $line);
        static::updateLineTransferMovement($transfer->codalmacendestino, $line->cantidad, $transfer, $line);
    }

    /**
     * @param string $reference
     * @param string $codalmacen
     * @param int $docid
     * @param string $docmodel
     * @param string $datetime
     *
     * @return float
     */
    protected static function getStockSum($reference, $codalmacen, $docid, $docmodel, $datetime)
    {
        $sum = 0.0;
        $movement = new MovimientoStock();
        $date = date(MovimientoStock::DATE_STYLE, strtotime($datetime));
        $where = [
            new DataBaseWhere('codalmacen', $codalmacen),
            new DataBaseWhere('fecha', $date, '<='),
            new DataBaseWhere('referencia', $reference)
        ];
        foreach ($movement->all($where, [], 0, 0) as $move) {
            // exclude selected movement
            if ($move->docid == $docid && $move->docmodel == $docmodel) {
                continue;
            }

            if (strtotime($datetime) > strtotime($move->fecha . ' ' . $move->hora)) {
                $sum += $move->cantidad;
            }
        }

        return $sum;
    }

    /**
     * @param TransformerDocument $doc
     *
     * @return bool
     */
    protected static function ignoredState($doc): bool
    {
        // check or add the status to the list
        if (!isset(self::$docStates[$doc->idestado])) {
            self::$docStates[$doc->idestado] = $doc->getStatus();
        }

        // ignore status with actualizastock == 0
        return empty(self::$docStates[$doc->idestado]->actualizastock);
    }

    /**
     * @param string $codalmacen
     * @param float $cantidad
     * @param TransferenciaStock $transfer
     * @param LineaTransferenciaStock $line
     *
     * @return bool
     */
    protected static function updateLineTransferMovement($codalmacen, $cantidad, $transfer, $line)
    {
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $codalmacen),
            new DataBaseWhere('docid', $transfer->primaryColumnValue()),
            new DataBaseWhere('docmodel', $transfer->modelClassName()),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if (false === $movement->loadFromCode('', $where)) {
            $movement->codalmacen = $codalmacen;
            $movement->docid = $transfer->primaryColumnValue();
            $movement->docmodel = $transfer->modelClassName();
            $movement->idproducto = $line->getVariant()->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($cantidad)) {
                return true;
            }
        }

        $movement->cantidad = $cantidad;
        $movement->documento = static::toolBox()->i18n()->trans($transfer->modelClassName()) . ' ' . $transfer->primaryColumnValue();
        $movement->fecha = date(MovimientoStock::DATE_STYLE, strtotime($transfer->fecha));
        $movement->hora = date(MovimientoStock::HOUR_STYLE, strtotime($transfer->fecha));
        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    /**
     * @return ToolBox
     */
    protected static function toolBox()
    {
        return new ToolBox();
    }
}

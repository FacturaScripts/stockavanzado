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

namespace FacturaScripts\Plugins\StockAvanzado\Lib;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Core\Model\Base\TransformerDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\LineaTransferenciaStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\TransferenciaStock;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\StockAvanzado\Contract\StockMovementModInterface;

/**
 * @author       Carlos Garcia Gomez            <carlos@facturascripts.com>
 * @collaborator Jerónimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class StockMovementManager
{
    const JOB_NAME = 'rebuild-movements';
    const JOB_PERIOD = '99 years';

    /** @var array */
    private static $docStates = [];

    /** @var int|null */
    private static $idproducto = null;

    /** @var StockMovementModInterface[] */
    private static $mods = [];

    /** @var array */
    private static $products = [];

    /** @var array */
    private static $variants = [];

    public static function addMod(StockMovementModInterface $mod): void
    {
        self::$mods[] = $mod;
    }

    public static function deleteLineCounting(LineaConteoStock $line, ConteoStock $stockCount): bool
    {
        $docid = $stockCount->primaryColumnValue();
        $docmodel = $stockCount->modelClassName();

        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $stockCount->codalmacen),
            new DataBaseWhere('docid', $docid),
            new DataBaseWhere('docmodel', $docmodel),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if (false === $movement->loadFromCode('', $where)) {
            return true;
        }

        return $movement->delete();
    }

    public static function deleteLineTransfer(LineaTransferenciaStock $line, TransferenciaStock $stockCount): bool
    {
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $stockCount->codalmacenorigen),
            new DataBaseWhere('docid', $stockCount->primaryColumnValue()),
            new DataBaseWhere('docmodel', $stockCount->modelClassName()),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if ($movement->loadFromCode('', $where) && false === $movement->delete()) {
            return false;
        }

        $where2 = [
            new DataBaseWhere('codalmacen', $stockCount->codalmacendestino),
            new DataBaseWhere('docid', $stockCount->primaryColumnValue()),
            new DataBaseWhere('docmodel', $stockCount->modelClassName()),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if ($movement->loadFromCode('', $where2) && false === $movement->delete()) {
            return false;
        }

        return true;
    }

    public static function rebuild(?int $idproducto = null): void
    {
        static::$idproducto = $idproducto;

        // removes all movements
        $movement = new MovimientoStock();
        $movement->deleteAll(static::$idproducto);

        // saves movements from business documents
        static::rebuildMovements();

        // run mods
        foreach (self::$mods as $mod) {
            $mod->run(static::$idproducto);
        }

        // save movements from transference
        $transferenciaStock = new TransferenciaStock();
        foreach ($transferenciaStock->all([], [], 0, 0) as $transfer) {
            foreach ($transfer->getLines() as $line) {
                $variant = $line->getVariant();
                $product = $variant->getProducto();

                // omitimos el producto si no es el que buscamos
                if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                    continue;
                }

                static::updateLineTransfer($line, $transfer);
            }
        }

        // save movements from stock counts
        $conteoStock = new ConteoStock();
        foreach ($conteoStock->all([], [], 0, 0) as $conteo) {
            foreach ($conteo->getLines(['fecha' => 'ASC']) as $line) {
                $product = $line->getProducto();

                // omitimos el producto si no es el que buscamos
                if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                    continue;
                }

                static::updateLineCounting($line, $conteo);
            }
        }
    }

    public static function transferLine(BusinessDocumentLine $line, TransformerDocument $doc, string $fromCodalmacen, string $toCodalmacen): void
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

    public static function updateLine(BusinessDocumentLine $line, array $prevData, TransformerDocument $doc): void
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
        $movement->documento = Tools::lang()->trans($doc->modelClassName()) . ' ' . $doc->codigo;
        $movement->fecha = $doc->fecha;
        $movement->hora = $doc->hora;
        empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    public static function updateLineCounting(LineaConteoStock $line, ConteoStock $stockCount): bool
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
        $movement->documento = Tools::lang()->trans($stockCount->modelClassName()) . ' ' . $stockCount->primaryColumnValue();
        $movement->fecha = Tools::date($line->fecha);
        $movement->hora = Tools::hour($line->fecha);
        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    public static function updateLineTransfer(LineaTransferenciaStock $line, TransferenciaStock $transfer): void
    {
        static::updateLineTransferMovement($transfer->codalmacenorigen, $line->cantidad * -1, $transfer, $line);
        static::updateLineTransferMovement($transfer->codalmacendestino, $line->cantidad, $transfer, $line);
    }

    protected static function getProduct(string $reference): Producto
    {
        $variant = static::getVariant($reference);
        if (!isset(self::$products[$variant->idproducto])) {
            self::$products[$variant->idproducto] = $variant->getProducto();
        }

        return self::$products[$variant->idproducto];
    }

    protected static function getVariant(string $referencia): Variante
    {
        if (!isset(self::$variants[$referencia])) {
            $variante = new Variante();
            $where = [new DataBaseWhere('referencia', $referencia)];
            $variante->loadFromCode('', $where);
            self::$variants[$referencia] = $variante;
        }

        return self::$variants[$referencia];
    }

    protected static function getStockSum(string $reference, string $codalmacen, int $docid, string $docmodel, string $datetime): float
    {
        $sum = 0.0;
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $codalmacen),
            new DataBaseWhere('fecha', Tools::date($datetime), '<='),
            new DataBaseWhere('referencia', $reference)
        ];
        foreach ($movement->all($where, ['fecha' => 'ASC'], 0, 0) as $move) {
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

    protected static function ignoredState(TransformerDocument $doc): bool
    {
        // check or add the status to the list
        if (!isset(self::$docStates[$doc->idestado])) {
            self::$docStates[$doc->idestado] = $doc->getStatus();
        }

        // ignore status with actualizastock == 0
        return empty(self::$docStates[$doc->idestado]->actualizastock);
    }

    protected static function rebuildMovements(): void
    {
        $limit = 1000;
        $models = [new AlbaranProveedor(), new FacturaProveedor(), new AlbaranCliente(), new FacturaCliente()];
        foreach ($models as $model) {
            $offset = 0;
            $docs = $model->all([], ['fecha' => 'DESC'], $offset, $limit);

            while (count($docs) > 0) {
                foreach ($docs as $doc) {
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
                        $product = static::getProduct($line->referencia);
                        if (empty($product->idproducto) || $product->nostock) {
                            continue;
                        }

                        // omitimos el producto si no es el que buscamos
                        if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                            continue;
                        }

                        $prevData['actualizastock'] = $line->actualizastock;
                        $prevData['cantidad'] = 0.0;
                        static::updateLine($line, $prevData, $doc);
                    }
                }

                $offset += $limit;
                $docs = $model->all([], ['fecha' => 'DESC'], $offset, $limit);
            }
        }
    }

    protected static function updateLineTransferMovement(string $codalmacen, float $cantidad, TransferenciaStock $transfer, LineaTransferenciaStock $line): bool
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
        $movement->documento = Tools::lang()->trans($transfer->modelClassName()) . ' ' . $transfer->primaryColumnValue();
        $movement->fecha = Tools::date($transfer->fecha);
        $movement->hora = Tools::hour($transfer->fecha);
        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }
}

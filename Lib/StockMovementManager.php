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

use FacturaScripts\Core\Base\DataBase;
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

    public static function addLineBusinessDocument(BusinessDocumentLine $line, array $prevData, TransformerDocument $doc): void
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

    public static function addLineCounting(LineaConteoStock $line, ConteoStock $stockCount, float $stock): bool
    {
        $docid = $stockCount->primaryColumnValue();
        $docmodel = $stockCount->modelClassName();
        // usamos la fecha de ejecución del conteo para calcular el stock previo
        $stockSum = static::getStockSum(
            $line->referencia,
            $stockCount->codalmacen,
            $docid,
            $docmodel,
            $stockCount->fechafin
        );
        $cantidad = $stock - $stockSum;

        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $stockCount->codalmacen),
            new DataBaseWhere('docid', $docid),
            new DataBaseWhere('docmodel', $docmodel),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if (false === $movement->loadFromCode('', $where)) {
            $movement->documento = Tools::lang()->trans($stockCount->modelClassName()) . ' ' . $stockCount->primaryColumnValue();
            $movement->fecha = Tools::date($stockCount->fechafin);
            $movement->hora = Tools::hour($stockCount->fechafin);
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
        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    public static function addLineTransferStock(LineaTransferenciaStock $line, TransferenciaStock $transfer): void
    {
        static::addLineTransferStockMovement($transfer->codalmacenorigen, $line->cantidad * -1, $transfer, $line);
        static::addLineTransferStockMovement($transfer->codalmacendestino, $line->cantidad, $transfer, $line);
    }

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
        if (false === static::deleteLineTransferMovement($stockCount->codalmacenorigen, $line, $stockCount)) {
            return false;
        }

        return static::deleteLineTransferMovement($stockCount->codalmacendestino, $line, $stockCount);
    }

    public static function rebuild(?int $idproducto = null): void
    {
        static::$idproducto = $idproducto;

        // eliminamos todos los movimientos de stock
        static::deleteMovements();

        // creamos los movimientos de los documentos de compra y venta
        static::rebuildBusinessDocument();

        // creamos los movimientos de las transferencias de stock
        static::rebuildTransferStock();

        // creamos los movimientos de los conteos de stock
        static::rebuildStockCounting();

        // añadimos los mods para otros plugins
        foreach (self::$mods as $mod) {
            $mod->run(static::$idproducto);
        }
    }

    protected static function addLineTransferStockMovement(string $codalmacen, float $cantidad, TransferenciaStock $transfer, LineaTransferenciaStock $line): bool
    {
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $codalmacen),
            new DataBaseWhere('docid', $transfer->primaryColumnValue()),
            new DataBaseWhere('docmodel', $transfer->modelClassName()),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if (false === $movement->loadFromCode('', $where)) {
            $movement->documento = Tools::lang()->trans($transfer->modelClassName()) . ' ' . $transfer->primaryColumnValue();
            $movement->fecha = Tools::date($transfer->fecha);
            $movement->hora = Tools::hour($transfer->fecha);
            $movement->codalmacen = $codalmacen;
            $movement->docid = $transfer->primaryColumnValue();
            $movement->docmodel = $transfer->modelClassName();
            $movement->idproducto = $line->getVariant()->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($cantidad)) {
                return true;
            }
        }

        $movement->cantidad += $cantidad;
        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    public static function addTransferLine(BusinessDocumentLine $line, TransformerDocument $doc, string $fromCodalmacen, string $toCodalmacen): void
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

    protected static function deleteLineTransferMovement(string $codalmacen, LineaTransferenciaStock $line, TransferenciaStock $stockCount): bool
    {
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $codalmacen),
            new DataBaseWhere('docid', $stockCount->primaryColumnValue()),
            new DataBaseWhere('docmodel', $stockCount->modelClassName()),
            new DataBaseWhere('referencia', $line->referencia)
        ];
        if ($movement->loadFromCode('', $where) && false === $movement->delete()) {
            return false;
        }

        return true;
    }

    protected static function deleteMovements(): bool
    {
        $db = new DataBase();

        $sql = 'DELETE FROM stocks_movimientos';
        if (!is_null(static::$idproducto)) {
            $sql .= ' WHERE idproducto = ' . $db->var2str(static::$idproducto);
        }

        return $db->exec($sql);
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
        $where = [
            new DataBaseWhere('codalmacen', $codalmacen),
            new DataBaseWhere('fecha', Tools::date($datetime), '<='),
            new DataBaseWhere('referencia', $reference)
        ];
        foreach (MovimientoStock::all($where, ['id' => 'ASC'], 0, 0) as $move) {
            // excluir movimiento seleccionado
            if ($move->docid == $docid && $move->docmodel == $docmodel) {
                continue;
            }

            if (strtotime($move->fecha . ' ' . $move->hora) <= strtotime($datetime)) {
                $sum += $move->cantidad;
            }
        }

        return $sum;
    }

    protected static function ignoredBusinessDocumentState(TransformerDocument $doc): bool
    {
        // comprobar o agregar el estado a la lista
        if (!isset(self::$docStates[$doc->idestado])) {
            self::$docStates[$doc->idestado] = $doc->getStatus();
        }

        // Ignorar estado con actualizastock == 0
        return empty(self::$docStates[$doc->idestado]->actualizastock);
    }

    protected static function rebuildBusinessDocument(): void
    {
        $limit = 1000;
        $models = [new AlbaranProveedor(), new FacturaProveedor(), new AlbaranCliente(), new FacturaCliente()];
        foreach ($models as $model) {
            $offset = 0;
            $docs = $model->all([], ['fecha' => 'DESC'], $offset, $limit);

            while (count($docs) > 0) {
                foreach ($docs as $doc) {
                    // Saltar estados que no modifican el stock
                    if (static::ignoredBusinessDocumentState($doc)) {
                        continue;
                    }

                    foreach ($doc->getLines() as $line) {
                        // saltamos líneas sin referencia
                        if (empty($line->referencia)) {
                            continue;
                        }

                        // Omitir productos faltantes o productos sin gestión de stock
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
                        static::addLineBusinessDocument($line, $prevData, $doc);
                    }
                }

                $offset += $limit;
                $docs = $model->all([], ['fecha' => 'DESC'], $offset, $limit);
            }
        }
    }

    protected static function rebuildStockCounting(): void
    {
        $where = [new DataBaseWhere('completed', true)];
        foreach (ConteoStock::all($where, ['idconteo' => 'ASC'], 0, 0) as $conteo) {
            // primero recorremos las líneas para obtener el stock actual por referencia
            $stocks = [];
            foreach ($conteo->getLines() as $line) {
                if (false === isset($stocks[$line->referencia])) {
                    $stocks[$line->referencia] = $line->cantidad;
                    continue;
                }
                $stocks[$line->referencia] += $line->cantidad;
            }

            // ahora generamos los movimientos de stock
            foreach ($conteo->getLines(['fecha' => 'ASC']) as $line) {
                $product = $line->getProducto();

                // omitimos el producto si no es el que buscamos
                if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                    continue;
                }

                static::addLineCounting($line, $conteo, $stocks[$line->referencia]);
            }
        }
    }

    protected static function rebuildTransferStock(): void
    {
        $where = [new DataBaseWhere('completed', true)];
        foreach (TransferenciaStock::all($where, ['idtrans' => 'ASC'], 0, 0) as $transfer) {
            foreach ($transfer->getLines() as $line) {
                $variant = $line->getVariant();
                $product = $variant->getProducto();

                // omitimos el producto si no es el que buscamos
                if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                    continue;
                }

                static::addLineTransferStock($line, $transfer);
            }
        }
    }
}

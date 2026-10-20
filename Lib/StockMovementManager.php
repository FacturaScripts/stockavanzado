<?php

/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2026 Carlos García Gómez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Core\Model\Base\TransformerDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\DocTransformation;
use FacturaScripts\Dinamic\Model\LineaAlbaranCliente;
use FacturaScripts\Dinamic\Model\LineaAlbaranProveedor;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\LineaFacturaCliente;
use FacturaScripts\Dinamic\Model\LineaFacturaProveedor;
use FacturaScripts\Dinamic\Model\LineaTransferenciaStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\TransferenciaStock;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Dinamic\Model\WorkEvent;
use FacturaScripts\Plugins\StockAvanzado\Contract\StockMovementModInterface;
use Throwable;

/**
 * Está clase se encarga de gestionar los movimientos de stock.
 *
 * @author       Carlos García Gómez            <carlos@facturascripts.com>
 * @collaborator Jerónimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class StockMovementManager
{
    /** @var bool indica si la caché de documentos y transformaciones está activa */
    private static $cacheEnabled = false;

    /** @var array líneas de documento cacheadas, indexadas por modelo|id */
    private static $cacheDocLines = [];

    /** @var array documentos de línea cacheados, indexados por clase de línea|id documento */
    private static $cacheLineDocuments = [];

    /** @var array primera transformación de cada línea hija, indexadas por modelo|id e idlinea2 */
    private static $cacheTransAsChild = [];

    /** @var array transformaciones de cada línea padre, indexadas por modelo|id e idlinea1 */
    private static $cacheTransAsParent = [];

    /** @var int|null */
    private static $idproducto = null;

    /** @var StockMovementModInterface[] */
    private static $mods = [];

    /** @var array */
    private static $pendingSaldos = [];

    /** @var array */
    private static $products = [];

    /**
     * Se incrementa en cada rebuild. WorkQueue descarta en todo el proceso los eventos ya enviados
     * o ejecutados con el mismo hash, así que lo añadimos a los parámetros para poder volver a
     * encolar un producto después de que el worker lo haya reconstruido.
     *
     * @var int
     */
    private static $rebuildRound = 0;

    /** @var bool */
    private static $rebuilding = false;

    /** @var array */
    private static $variants = [];

    public static function addLineBusinessDocument(BusinessDocumentLine $line, TransformerDocument $doc): void
    {
        if (empty($line->referencia)) {
            return;
        }

        $opened = static::openCache();
        try {
            if (false === static::mustCreateBusinessDocumentMovement($line)) {
                return;
            }

            $movement = static::getBusinessDocumentMovement($line, $doc);
            $movement->cantidad = static::getBusinessDocumentMovementQuantityForReference($line, $doc);
            $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
            $movement->fecha = $doc->fecha;
            $movement->hora = $doc->hora;
            $movement->saldo = 0;

            if (empty($movement->cantidad) && false === static::shouldSaveZeroBusinessDocumentMovement($line)) {
                $movement->delete();
                static::updateReferenceSaldos($doc->codalmacen, $line->referencia);
                return;
            }

            $movement->save();
            static::updateReferenceSaldos($doc->codalmacen, $line->referencia);
        } finally {
            if ($opened) {
                static::closeCache();
            }
        }
    }

    public static function addLineCounting(LineaConteoStock $line, ConteoStock $stockCount, float $stock): bool
    {
        $movement = static::findMovement($stockCount->codalmacen, $stockCount, $line->referencia);
        if (false === $movement->exists()) {
            // no existe, lo creamos
            $movement->documento = Tools::trans($stockCount->modelClassName()) . ' ' . $stockCount->id();
            $movement->fecha = Tools::date($stockCount->fechafin);
            $movement->hora = Tools::hour($stockCount->fechafin);
            $movement->codalmacen = $stockCount->codalmacen;
            $movement->docid = $stockCount->id();
            $movement->docmodel = $stockCount->modelClassName();
            $movement->idproducto = $line->idproducto;
            $movement->referencia = $line->referencia;
        }

        // actualizamos la cantidad y el saldo
        $movement->cantidad = 0;
        $movement->saldo = $stock;

        if (false === $movement->save()) {
            return false;
        }

        static::updateReferenceSaldos($stockCount->codalmacen, $line->referencia);
        return true;
    }

    public static function addLineTransferStock(LineaTransferenciaStock $line, TransferenciaStock $transfer): void
    {
        static::addLineTransferStockMovement($transfer->codalmacenorigen, $line->cantidad * -1, $transfer, $line);
        static::addLineTransferStockMovement($transfer->codalmacendestino, $line->cantidad, $transfer, $line);
    }

    public static function addMod(StockMovementModInterface $mod): void
    {
        static::$mods[] = $mod;
    }

    public static function deleteMovements(): bool
    {
        $db = new DataBase();

        $sql = 'DELETE FROM stocks_movimientos';
        if (!is_null(static::$idproducto)) {
            $sql .= ' WHERE idproducto = ' . $db->var2str(static::$idproducto);
        }

        return $db->exec($sql);
    }

    /**
     * Encola la reconstrucción de los movimientos de stock del producto, que hace el worker
     * RebuildStockMovements. Los eventos repetidos del mismo producto se descartan.
     * Con $rebuildStock, el worker recalcula también el stock aunque stock_rebuild esté desactivado.
     */
    public static function queueProductRebuild(?int $idproducto, bool $rebuildStock = false): void
    {
        if (empty($idproducto) || static::$rebuilding) {
            return;
        }

        $params = ['round' => static::$rebuildRound];
        if ($rebuildStock) {
            $params['stock'] = true;
        }

        WorkQueue::send('Model.Producto.rebuildStockMovements', (string)$idproducto, $params);
    }

    /**
     * Encola la reconstrucción de los siguientes productos con actividad de stock: líneas de documentos,
     * transferencias, conteos o movimientos. Omite los que ya tienen una reconstrucción pendiente.
     *
     * @return int|null el último idproducto revisado, o null si no quedan más productos.
     */
    public static function queueRebuildPage(int $afterId, int $limit = 100): ?int
    {
        $db = new DataBase();
        $tables = [
            'lineasalbaranescli', 'lineasalbaranesprov', 'lineasfacturascli', 'lineasfacturasprov',
            'stocks_lineasconteos', 'stocks_lineastransferencias', 'stocks_movimientos'
        ];

        // cada subconsulta devuelve como mucho una página, así no recorremos tablas enteras por página
        $parts = [];
        foreach ($tables as $table) {
            if ($db->tableExists($table)) {
                $parts[] = '(SELECT DISTINCT idproducto FROM ' . $table
                    . ' WHERE idproducto > ' . $db->var2str($afterId)
                    . ' ORDER BY idproducto ASC LIMIT ' . $limit . ')';
            }
        }
        if (empty($parts)) {
            return null;
        }

        $sql = 'SELECT idproducto FROM (' . implode(' UNION ', $parts) . ') AS productos ORDER BY idproducto ASC';
        $ids = array_map('intval', array_column($db->selectLimit($sql, $limit), 'idproducto'));
        if (empty($ids)) {
            return null;
        }

        // los eventos repetidos sólo se descartan dentro del mismo proceso, así que consultamos los pendientes
        $pending = [];
        $where = [
            Where::eq('done', false),
            Where::eq('name', 'Model.Producto.rebuildStockMovements'),
            Where::in('value', array_map('strval', $ids))
        ];
        foreach (WorkEvent::all($where, [], 0, 0) as $event) {
            $pending[(int)$event->value] = true;
        }

        foreach ($ids as $idproducto) {
            if (false === isset($pending[$idproducto])) {
                static::queueProductRebuild($idproducto);
            }
        }

        return count($ids) < $limit ? null : end($ids);
    }

    public static function rebuild(?int $idproducto = null, array &$messages = [], bool $cron = false): void
    {
        static::$rebuildRound++;
        self::setIdProducto($idproducto);

        // reconstruimos en una transacción para no dejar el producto sin movimientos si algo falla
        $db = new DataBase();
        $newTransaction = false === $db->inTransaction() && $db->beginTransaction();

        // eliminamos todos los movimientos de stock
        if (!static::deleteMovements()) {
            if ($newTransaction) {
                $db->rollback();
            }
            self::setIdProducto(null);
            $messages[] = 'error-deleting-movements';
            Tools::log('audit')->warning('error-deleting-movements');
            return;
        }

        // activamos el modo rebuild para aplazar el recálculo de saldos
        static::$rebuilding = true;
        static::$pendingSaldos = [];

        try {
            if (!empty(static::$idproducto)) {
                static::rebuildBusinessDocument();

                // creamos los movimientos de las transferencias de stock
                static::rebuildTransferStock();

                // creamos los movimientos de los conteos de stock
                static::rebuildStockCounting();
            }

            // añadimos los mods para otros plugins
            foreach (static::$mods as $mod) {
                $mod->run(static::$idproducto);
            }

            // recalculamos los saldos una sola vez por (almacén, referencia)
            $pending = static::$pendingSaldos;
            static::$pendingSaldos = [];
            static::$rebuilding = false;
            foreach ($pending as [$codalmacen, $referencia]) {
                static::updateReferenceSaldos($codalmacen, $referencia);
            }

            if ($newTransaction) {
                $db->commit();
            }
        } catch (Throwable $th) {
            static::$pendingSaldos = [];
            static::$rebuilding = false;
            if ($newTransaction && $db->inTransaction()) {
                $db->rollback();
            }
            self::setIdProducto(null);
            throw $th;
        }

        // reseteamos el idproducto
        self::setIdProducto(null);

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log('audit')->warning($message);
        }
    }

    public static function setIdProducto(?int $idproducto): void
    {
        static::$idproducto = $idproducto;
    }

    /**
     * Recalcula los saldos de todos los pares (almacén, referencia) con movimientos del producto.
     */
    public static function updateProductSaldos(int $idproducto): void
    {
        $db = new DataBase();
        $sql = 'SELECT codalmacen, referencia FROM stocks_movimientos'
            . ' WHERE idproducto = ' . $db->var2str($idproducto)
            . ' GROUP BY codalmacen, referencia';
        foreach ($db->select($sql) as $row) {
            static::updateReferenceSaldos($row['codalmacen'], $row['referencia']);
        }
    }

    public static function updateReferenceSaldos(string $codalmacen, string $referencia): void
    {
        // durante un rebuild aplazamos el recálculo y lo hacemos una sola vez por par al final
        if (static::$rebuilding) {
            static::$pendingSaldos[$codalmacen . '|' . $referencia] = [$codalmacen, $referencia];
            return;
        }

        $saldo = 0.0;
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('referencia', $referencia)
        ];
        $movements = MovimientoStock::all($where, ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC'], 0, 0);
        usort($movements, function (MovimientoStock $a, MovimientoStock $b) {
            $dateA = strtotime($a->fecha . ' ' . $a->hora);
            $dateB = strtotime($b->fecha . ' ' . $b->hora);
            if ($dateA !== $dateB) {
                return $dateA <=> $dateB;
            }

            $priority = static::getMovementSortPriority($a->docmodel) <=> static::getMovementSortPriority($b->docmodel);
            if ($priority !== 0) {
                return $priority;
            }

            return $a->id() <=> $b->id();
        });

        foreach ($movements as $movement) {
            if ($movement->docmodel === 'ConteoStock') {
                $saldo = $movement->saldo;
                continue;
            }

            // comparamos con tolerancia para no regrabar movimientos por errores de redondeo
            $saldo += $movement->cantidad;
            if (abs($movement->saldo - $saldo) > 0.000001) {
                $movement->saldo = $saldo;
                $movement->save();
            }
        }
    }

    protected static function addLineTransferStockMovement(string $codalmacen, float $cantidad, TransferenciaStock $transfer, LineaTransferenciaStock $line): bool
    {
        $movement = static::findMovement($codalmacen, $transfer, $line->referencia);
        if (false === $movement->exists()) {
            // no existe, lo creamos
            $movement->documento = Tools::trans($transfer->modelClassName()) . ' ' . $transfer->id();
            $movement->fecha = Tools::date($transfer->fecha);
            $movement->hora = Tools::hour($transfer->fecha);
            $movement->codalmacen = $codalmacen;
            $movement->docid = $transfer->id();
            $movement->docmodel = $transfer->modelClassName();
            $movement->idproducto = $line->getVariant()->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($cantidad)) {
                return true;
            }
        }

        // actualizamos la cantidad
        $movement->cantidad += $cantidad;
        $movement->saldo = 0;

        $result = empty($movement->cantidad) ? $movement->delete() : $movement->save();
        static::updateReferenceSaldos($codalmacen, $line->referencia);
        return $result;
    }

    /**
     * Desactiva y vacía la caché de documentos y transformaciones.
     */
    protected static function closeCache(): void
    {
        static::$cacheEnabled = false;
        static::$cacheDocLines = [];
        static::$cacheLineDocuments = [];
        static::$cacheTransAsChild = [];
        static::$cacheTransAsParent = [];
    }

    protected static function findMovement(string $codalmacen, $doc, string $referencia): MovimientoStock
    {
        $movement = new MovimientoStock();
        $movement->loadWhere([
            Where::eq('codalmacen', $codalmacen),
            Where::eq('docid', $doc->id()),
            Where::eq('docmodel', $doc->modelClassName()),
            Where::eq('referencia', $referencia),
        ]);
        return $movement;
    }

    protected static function getBusinessDocumentMovement(BusinessDocumentLine $line, TransformerDocument $doc): MovimientoStock
    {
        $movement = static::findMovement($doc->codalmacen, $doc, $line->referencia);
        if ($movement->exists()) {
            return $movement;
        }

        $movement->codalmacen = $doc->codalmacen;
        $movement->docid = $doc->id();
        $movement->docmodel = $doc->modelClassName();
        $movement->idproducto = $line->idproducto ?? $line->getProducto()->idproducto;
        $movement->referencia = $line->referencia;
        return $movement;
    }

    protected static function getBusinessDocumentMovementQuantity(BusinessDocumentLine $line): float
    {
        $transformation = static::getTransformationFromChildLine($line);
        if (false === is_null($transformation)) {
            return $line->exists() ?
                static::getCurrentBusinessDocumentLineQuantity($line) - static::getSignedTransformationQuantity($transformation) :
                0.0;
        }

        return static::getCurrentBusinessDocumentLineQuantity($line) + static::getSignedChildrenTransformationQuantity($line);
    }

    protected static function getBusinessDocumentMovementQuantityForReference(BusinessDocumentLine $line, TransformerDocument $doc): float
    {
        // El hook se dispara antes de persistir la línea, así que $line puede no estar
        // todavía en $doc->getLines(). Tratamos $line como autoritativa para su propia
        // contribución y sumamos las del resto de líneas con la misma referencia.
        $linePk = $line->id();
        $quantity = static::getBusinessDocumentMovementQuantity($line);
        foreach (static::getDocLines($doc) as $docLine) {
            if ($docLine->referencia !== $line->referencia) {
                continue;
            }
            if (!empty($linePk) && $docLine->id() == $linePk) {
                continue;
            }
            $quantity += static::getBusinessDocumentMovementQuantity($docLine);
        }
        return $quantity;
    }

    protected static function getCurrentBusinessDocumentLineQuantity(BusinessDocumentLine $line): float
    {
        return in_array($line->actualizastock, [-1, 1], true) ?
            $line->actualizastock * ((float)$line->cantidad - (float)$line->servido) :
            0.0;
    }

    /**
     * Devuelve las líneas de un documento, cacheadas si la caché está activa.
     *
     * @return BusinessDocumentLine[]
     */
    protected static function getDocLines(TransformerDocument $doc): array
    {
        if (false === static::$cacheEnabled) {
            return $doc->getLines();
        }

        $key = $doc->modelClassName() . '|' . $doc->id();
        if (false === isset(static::$cacheDocLines[$key])) {
            static::$cacheDocLines[$key] = $doc->getLines();
        }

        return static::$cacheDocLines[$key];
    }

    /**
     * Devuelve el documento de una línea, cacheado si la caché está activa. Sólo para lectura.
     */
    protected static function getLineDocument(BusinessDocumentLine $line)
    {
        if (false === static::$cacheEnabled) {
            return $line->getDocument();
        }

        $key = get_class($line) . '|' . $line->documentColumnValue();
        if (false === isset(static::$cacheLineDocuments[$key])) {
            static::$cacheLineDocuments[$key] = $line->getDocument();
        }

        return static::$cacheLineDocuments[$key];
    }

    protected static function getMovementSortPriority(string $docmodel): int
    {
        switch ($docmodel) {
            case 'AlbaranCliente':
            case 'AlbaranProveedor':
                return 10;

            case 'TransferenciaStock':
                return 20;

            case 'ConteoStock':
                return 30;

            case 'FacturaCliente':
            case 'FacturaProveedor':
                return 40;
        }

        return 50;
    }

    protected static function getProduct(string $reference): Producto
    {
        $variant = static::getVariant($reference);

        if (!isset(static::$products[$variant->idproducto])) {
            static::$products[$variant->idproducto] = $variant->getProducto();
        }

        return static::$products[$variant->idproducto];
    }

    protected static function getSignedChildrenTransformationQuantity(BusinessDocumentLine $line): float
    {
        $doc = static::getLineDocument($line);
        if (empty($doc->id()) || empty($line->id())) {
            return 0.0;
        }

        $quantity = 0.0;
        foreach (static::getTransformationsAsParent($doc, $line) as $transformation) {
            if (false === static::isSupportedStockTransformation($transformation)) {
                continue;
            }

            $quantity += static::getSignedTransformationQuantity($transformation);
        }

        return $quantity;
    }

    protected static function getSignedTransformationQuantity(DocTransformation $transformation): float
    {
        return static::getTransformationStockMode($transformation) * (float)$transformation->cantidad;
    }

    protected static function getTransformationFromChildLine(BusinessDocumentLine $line): ?DocTransformation
    {
        $doc = static::getLineDocument($line);
        if (empty($doc->id()) || empty($line->id())) {
            return null;
        }

        if (static::$cacheEnabled) {
            $key = static::preloadDocTransformations($doc->modelClassName(), $line->documentColumnValue());
            $transformation = static::$cacheTransAsChild[$key][$line->id()] ?? null;
            return $transformation && static::isSupportedStockTransformation($transformation) ? $transformation : null;
        }

        $where = [
            Where::eq('model2', $doc->modelClassName()),
            Where::eq('iddoc2', $line->documentColumnValue()),
            Where::eq('idlinea2', $line->id())
        ];
        foreach (DocTransformation::all($where, ['id' => 'ASC'], 0, 1) as $transformation) {
            if (static::isSupportedStockTransformation($transformation)) {
                return $transformation;
            }
        }

        return null;
    }

    protected static function getTransformationStockMode(DocTransformation $transformation): int
    {
        switch ($transformation->model2) {
            case 'FacturaCliente':
                return -1;

            case 'FacturaProveedor':
                return 1;
        }

        return 0;
    }

    /**
     * Devuelve las transformaciones en las que la línea es el origen.
     *
     * @return DocTransformation[]
     */
    protected static function getTransformationsAsParent($doc, BusinessDocumentLine $line): array
    {
        if (static::$cacheEnabled) {
            $key = static::preloadDocTransformations($doc->modelClassName(), $line->documentColumnValue());
            return static::$cacheTransAsParent[$key][$line->id()] ?? [];
        }

        $where = [
            Where::eq('model1', $doc->modelClassName()),
            Where::eq('iddoc1', $line->documentColumnValue()),
            Where::eq('idlinea1', $line->id())
        ];
        return DocTransformation::all($where, ['id' => 'ASC'], 0, 0);
    }

    protected static function getVariant(string $referencia): Variante
    {
        if (!isset(static::$variants[$referencia])) {
            $variante = new Variante();
            $variante->loadWhereEq('referencia', $referencia);
            static::$variants[$referencia] = $variante;
        }

        return static::$variants[$referencia];
    }

    protected static function isSupportedStockTransformation(DocTransformation $transformation): bool
    {
        return ($transformation->model1 === 'AlbaranCliente' && $transformation->model2 === 'FacturaCliente') ||
            ($transformation->model1 === 'AlbaranProveedor' && $transformation->model2 === 'FacturaProveedor');
    }

    protected static function mustCreateBusinessDocumentMovement(BusinessDocumentLine $line): bool
    {
        if (in_array($line->actualizastock, [-1, 1], true) || in_array($line->getOriginal('actualizastock'), [-1, 1], true)) {
            return true;
        }

        if (false === is_null(static::getTransformationFromChildLine($line))) {
            return true;
        }

        return !empty(static::getSignedChildrenTransformationQuantity($line));
    }

    /**
     * Activa la caché de documentos y transformaciones.
     *
     * @return bool true si esta llamada ha abierto la caché y debe cerrarla.
     */
    protected static function openCache(): bool
    {
        if (static::$cacheEnabled) {
            return false;
        }

        static::closeCache();
        static::$cacheEnabled = true;
        return true;
    }

    /**
     * Carga con dos consultas todas las transformaciones de un documento y devuelve su clave en la caché.
     */
    protected static function preloadDocTransformations(string $docmodel, $docid): string
    {
        $key = $docmodel . '|' . $docid;
        if (isset(static::$cacheTransAsChild[$key])) {
            return $key;
        }

        static::$cacheTransAsChild[$key] = [];
        static::$cacheTransAsParent[$key] = [];
        if (empty($docid)) {
            return $key;
        }

        // transformaciones en las que este documento es el hijo: sólo la primera de cada línea
        $where = [
            Where::eq('model2', $docmodel),
            Where::eq('iddoc2', $docid),
        ];
        foreach (DocTransformation::all($where, ['id' => 'ASC'], 0, 0) as $transformation) {
            if (false === isset(static::$cacheTransAsChild[$key][$transformation->idlinea2])) {
                static::$cacheTransAsChild[$key][$transformation->idlinea2] = $transformation;
            }
        }

        // transformaciones en las que este documento es el padre
        $where2 = [
            Where::eq('model1', $docmodel),
            Where::eq('iddoc1', $docid),
        ];
        foreach (DocTransformation::all($where2, ['id' => 'ASC'], 0, 0) as $transformation) {
            static::$cacheTransAsParent[$key][$transformation->idlinea1][] = $transformation;
        }

        return $key;
    }

    protected static function rebuildBusinessDocument(): void
    {
        $limit = 250;
        $models = [
            new LineaAlbaranProveedor(),
            new LineaFacturaProveedor(),
            new LineaAlbaranCliente(),
            new LineaFacturaCliente()
        ];
        foreach ($models as $model) {
            $where = [
                Where::notEq('cantidad', 0),
                Where::eq('idproducto', static::$idproducto),
                Where::isNotNull('referencia'),
            ];
            $offset = 0;

            // mantenemos la caché mientras las líneas sean del mismo documento: durante el rebuild
            // sólo se modifican movimientos, no documentos, líneas ni transformaciones
            $opened = static::openCache();
            $lastDocKey = null;
            try {
                $lines = $model->all($where, ['idlinea' => 'ASC'], $offset, $limit);
                while (count($lines) > 0) {
                    foreach ($lines as $line) {
                        // Omitir productos faltantes o productos sin gestión de stock
                        $product = static::getProduct($line->referencia);
                        if (empty($product->idproducto) || $product->nostock) {
                            continue;
                        }

                        $docKey = get_class($line) . '|' . $line->documentColumnValue();
                        if ($opened && null !== $lastDocKey && $docKey !== $lastDocKey) {
                            static::closeCache();
                            static::openCache();
                        }
                        $lastDocKey = $docKey;

                        static::addLineBusinessDocument($line, static::getLineDocument($line));
                    }

                    $offset += $limit;
                    $lines = $model->all($where, ['idlinea' => 'ASC'], $offset, $limit);
                }
            } finally {
                if ($opened) {
                    static::closeCache();
                }
            }
        }
    }

    protected static function rebuildStockCounting(): void
    {
        // recorremos todas las líneas de conteo de stock
        foreach (LineaConteoStock::allWhereEq('idproducto', static::$idproducto, ['idlinea' => 'ASC']) as $line) {
            // si el conteo no está finalizado, lo omitimos
            $conteo = $line->getConteo();
            if (!$conteo->completed) {
                continue;
            }

            // ahora generamos el movimiento de stock
            static::addLineCounting($line, $conteo, $line->cantidad);
        }
    }

    protected static function rebuildTransferStock(): void
    {
        // recorremos todas las líneas de transferencias de stock
        foreach (LineaTransferenciaStock::allWhereEq('idproducto', static::$idproducto, ['idlinea' => 'ASC']) as $line) {
            // si la transferencia no está finalizada, la omitimos
            $transfer = $line->getTransference();
            if (!$transfer->completed) {
                continue;
            }

            static::addLineTransferStock($line, $transfer);
        }
    }

    protected static function shouldSaveZeroBusinessDocumentMovement(BusinessDocumentLine $line): bool
    {
        if (false === $line->exists()) {
            return false;
        }

        return false === is_null(static::getTransformationFromChildLine($line));
    }
}

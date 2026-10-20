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
    /** @var array primera transformación de cada línea hija, indexadas por modelo|id e idlinea2 */
    private static $cacheTransAsChild = [];

    /** @var array transformaciones de cada línea padre, indexadas por modelo|id e idlinea1 */
    private static $cacheTransAsParent = [];

    /** @var int|null */
    private static $idproducto = null;

    /** @var StockMovementModInterface[] */
    private static $mods = [];

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

    /**
     * Crea o actualiza el movimiento de un conteo al completarlo. El saldo es la cantidad contada.
     */
    public static function addLineCounting(LineaConteoStock $line, ConteoStock $stockCount, float $stock): bool
    {
        $movement = static::findMovement($stockCount->codalmacen, $stockCount, $line->referencia);
        if (false === $movement->exists()) {
            $movement = static::newMovement(
                $line->idproducto,
                $stockCount,
                $stockCount->codalmacen,
                $line->referencia,
                Tools::date($stockCount->fechafin),
                Tools::hour($stockCount->fechafin)
            );
        }

        $movement->saldo = $stock;
        if (false === $movement->save()) {
            return false;
        }

        static::updateReferenceSaldos($stockCount->codalmacen, $line->referencia);
        return true;
    }

    public static function addMod(StockMovementModInterface $mod): void
    {
        static::$mods[] = $mod;
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

    /**
     * Sustituye los movimientos del producto por los que resultan de sus documentos, transferencias y conteos.
     * Los calcula en memoria, con sus saldos, y los guarda una sola vez. Después ejecuta los mods.
     */
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

        // durante el rebuild no se encolan productos
        static::$rebuilding = true;

        try {
            if (!empty(static::$idproducto)) {
                $movements = array_merge(
                    static::getBusinessDocumentMovements(),
                    static::getTransferMovements(),
                    static::getCountingMovements()
                );
                if (false === static::saveMovements($movements)) {
                    static::$rebuilding = false;
                    if ($newTransaction) {
                        $db->rollback();
                    }
                    self::setIdProducto(null);
                    $messages[] = 'record-save-error';
                    Tools::log('audit')->warning('record-save-error');
                    return;
                }
            }

            // los mods de otros plugins guardan sus movimientos sin saldo, así que recalculamos los saldos del producto
            foreach (static::$mods as $mod) {
                $mod->run(static::$idproducto);
            }
            if (!empty(static::$mods)) {
                static::updateProductSaldos(static::$idproducto);
            }

            static::$rebuilding = false;
            if ($newTransaction) {
                $db->commit();
            }
        } catch (Throwable $th) {
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

    /**
     * Ordena los movimientos como se acumulan sus saldos: fecha, hora, prioridad del documento e id.
     */
    public static function compareMovements(MovimientoStock $a, MovimientoStock $b): int
    {
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
    }

    /**
     * Devuelve el último movimiento de un almacén y referencia en el orden de los saldos, que es el que
     * tiene el stock final. Con la misma fecha y hora no basta con el id: decide la prioridad del documento.
     */
    public static function getLastMovement(string $codalmacen, string $referencia): MovimientoStock
    {
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('referencia', $referencia)
        ];
        $last = new MovimientoStock();
        if (false === $last->loadWhere($where, ['fecha' => 'DESC', 'hora' => 'DESC', 'id' => 'DESC'])) {
            return $last;
        }

        $where[] = Where::eq('fecha', $last->fecha);
        $where[] = Where::eq('hora', $last->hora);
        foreach (MovimientoStock::all($where, [], 0, 0) as $movement) {
            if (static::compareMovements($movement, $last) > 0) {
                $last = $movement;
            }
        }

        return $last;
    }

    public static function setIdProducto(?int $idproducto): void
    {
        static::$idproducto = $idproducto;
    }

    /**
     * Recalcula los saldos de todos los pares (almacén, referencia) con movimientos del producto,
     * o de todos los productos si no se indica ninguno.
     */
    public static function updateProductSaldos(?int $idproducto): void
    {
        $db = new DataBase();
        $sql = 'SELECT codalmacen, referencia FROM stocks_movimientos';
        if (null !== $idproducto) {
            $sql .= ' WHERE idproducto = ' . $db->var2str($idproducto);
        }
        $sql .= ' GROUP BY codalmacen, referencia';
        foreach ($db->select($sql) as $row) {
            static::updateReferenceSaldos($row['codalmacen'], $row['referencia']);
        }
    }

    public static function updateReferenceSaldos(string $codalmacen, string $referencia): void
    {
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('referencia', $referencia)
        ];
        $movements = MovimientoStock::all($where, ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC'], 0, 0);
        usort($movements, [static::class, 'compareMovements']);

        foreach (static::accumulateSaldos($movements) as $movement) {
            $movement->save();
        }
    }

    /**
     * Asigna el saldo acumulado a movimientos ya ordenados y devuelve los que han cambiado.
     * Un conteo no suma: fija el saldo de su almacén y referencia.
     *
     * @param MovimientoStock[] $movements
     *
     * @return MovimientoStock[]
     */
    protected static function accumulateSaldos(array $movements): array
    {
        $changed = [];
        $saldos = [];
        foreach ($movements as $movement) {
            $pair = $movement->codalmacen . '|' . $movement->referencia;
            $saldo = $movement->docmodel === 'ConteoStock' ?
                (float)$movement->saldo :
                ($saldos[$pair] ?? 0.0) + $movement->cantidad;
            $saldos[$pair] = $saldo;

            // comparamos con tolerancia para no regrabar movimientos por errores de redondeo
            if (abs($movement->saldo - $saldo) > 0.000001) {
                $movement->saldo = $saldo;
                $changed[] = $movement;
            }
        }

        return $changed;
    }

    /**
     * Vacía la caché de transformaciones.
     */
    protected static function clearCache(): void
    {
        static::$cacheTransAsChild = [];
        static::$cacheTransAsParent = [];
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

    /**
     * Calcula los movimientos de albaranes y facturas del producto: uno por documento, almacén y referencia.
     *
     * @return MovimientoStock[]
     */
    protected static function getBusinessDocumentMovements(): array
    {
        // sólo los productos que gestionan stock tienen movimientos de documentos
        $product = new Producto();
        if (false === $product->load(static::$idproducto) || $product->nostock) {
            return [];
        }

        // omitimos las líneas cuya referencia ya no es una variante del producto
        $references = [];
        foreach (Variante::allWhereEq('idproducto', static::$idproducto) as $variant) {
            $references[$variant->referencia] = true;
        }

        $movements = [];
        $keepZero = [];
        $limit = 250;
        $models = [new LineaAlbaranProveedor(), new LineaFacturaProveedor(), new LineaAlbaranCliente(), new LineaFacturaCliente()];
        foreach ($models as $model) {
            if (false === static::tableExists($model::tableName())) {
                continue;
            }

            // ordenamos por documento para cargar cada documento y sus transformaciones una sola vez
            $where = [Where::eq('idproducto', static::$idproducto), Where::isNotNull('referencia')];
            $order = [$model->documentColumn() => 'ASC', 'idlinea' => 'ASC'];
            $doc = null;
            $offset = 0;
            $lines = $model->all($where, $order, $offset, $limit);
            while (count($lines) > 0) {
                foreach ($lines as $line) {
                    if (false === isset($references[$line->referencia])) {
                        continue;
                    }

                    if (null === $doc || $doc->id() != $line->documentColumnValue()) {
                        $doc = $line->getDocument();
                        static::clearCache();
                    }

                    $key = static::movementKey($doc, $doc->codalmacen, $line->referencia);
                    $movements[$key] ??= static::newMovement(
                        static::$idproducto, $doc, $doc->codalmacen, $line->referencia, $doc->fecha, $doc->hora
                    );
                    $movements[$key]->cantidad += static::getBusinessDocumentLineQuantity($doc, $line);

                    // una factura que viene de un albarán guarda su movimiento aunque sea 0: recoge los cambios posteriores
                    if (null !== static::getTransformationFromChildLine($doc, $line)) {
                        $keepZero[$key] = true;
                    }
                }

                $offset += $limit;
                $lines = $model->all($where, $order, $offset, $limit);
            }
            static::clearCache();
        }

        foreach ($movements as $key => $movement) {
            if (abs($movement->cantidad) < 0.000001 && false === isset($keepZero[$key])) {
                unset($movements[$key]);
            }
        }

        return array_values($movements);
    }

    /**
     * Cantidad con la que una línea contribuye al movimiento de su documento.
     */
    protected static function getBusinessDocumentLineQuantity(TransformerDocument $doc, BusinessDocumentLine $line): float
    {
        // la línea de una factura que viene de un albarán sólo aporta la diferencia con lo facturado
        $transformation = static::getTransformationFromChildLine($doc, $line);
        if (null !== $transformation) {
            return static::getCurrentBusinessDocumentLineQuantity($line) - static::getSignedTransformationQuantity($transformation);
        }

        // la línea de un albarán conserva lo que ya se ha facturado desde ella
        $quantity = static::getCurrentBusinessDocumentLineQuantity($line);
        foreach (static::getTransformationsAsParent($doc, $line) as $transformation) {
            if (static::isSupportedStockTransformation($transformation)) {
                $quantity += static::getSignedTransformationQuantity($transformation);
            }
        }

        return $quantity;
    }

    /**
     * Calcula los movimientos de los conteos completados del producto. Su saldo es la cantidad contada.
     *
     * @return MovimientoStock[]
     */
    protected static function getCountingMovements(): array
    {
        if (false === static::tableExists(LineaConteoStock::tableName())) {
            return [];
        }

        $movements = [];
        $conteos = [];
        foreach (LineaConteoStock::allWhereEq('idproducto', static::$idproducto, ['idlinea' => 'ASC']) as $line) {
            $conteo = $conteos[$line->idconteo] ??= $line->getConteo();
            if (!$conteo->completed) {
                continue;
            }

            // un conteo puede tener varias líneas de la misma referencia: su saldo es la suma
            $key = static::movementKey($conteo, $conteo->codalmacen, $line->referencia);
            $movements[$key] ??= static::newMovement(
                static::$idproducto, $conteo, $conteo->codalmacen, $line->referencia,
                Tools::date($conteo->fechafin), Tools::hour($conteo->fechafin)
            );
            $movements[$key]->saldo += $line->cantidad;
        }

        return array_values($movements);
    }

    protected static function getCurrentBusinessDocumentLineQuantity(BusinessDocumentLine $line): float
    {
        return in_array($line->actualizastock, [-1, 1], true) ?
            $line->actualizastock * ((float)$line->cantidad - (float)$line->servido) :
            0.0;
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

    protected static function getSignedTransformationQuantity(DocTransformation $transformation): float
    {
        return static::getTransformationStockMode($transformation) * (float)$transformation->cantidad;
    }

    protected static function getTransformationFromChildLine(TransformerDocument $doc, BusinessDocumentLine $line): ?DocTransformation
    {
        $key = static::preloadDocTransformations($doc->modelClassName(), $doc->id());
        $transformation = static::$cacheTransAsChild[$key][$line->id()] ?? null;
        return $transformation && static::isSupportedStockTransformation($transformation) ? $transformation : null;
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
    protected static function getTransformationsAsParent(TransformerDocument $doc, BusinessDocumentLine $line): array
    {
        $key = static::preloadDocTransformations($doc->modelClassName(), $doc->id());
        return static::$cacheTransAsParent[$key][$line->id()] ?? [];
    }

    /**
     * Calcula los movimientos de las transferencias completadas del producto: salida en origen y entrada en destino.
     *
     * @return MovimientoStock[]
     */
    protected static function getTransferMovements(): array
    {
        if (false === static::tableExists(LineaTransferenciaStock::tableName())) {
            return [];
        }

        $movements = [];
        $transfers = [];
        foreach (LineaTransferenciaStock::allWhereEq('idproducto', static::$idproducto, ['idlinea' => 'ASC']) as $line) {
            $transfer = $transfers[$line->idtrans] ??= $line->getTransference();
            if (!$transfer->completed) {
                continue;
            }

            foreach ([[$transfer->codalmacenorigen, -1], [$transfer->codalmacendestino, 1]] as [$codalmacen, $sign]) {
                $key = static::movementKey($transfer, $codalmacen, $line->referencia);
                $movements[$key] ??= static::newMovement(
                    static::$idproducto, $transfer, $codalmacen, $line->referencia,
                    Tools::date($transfer->fecha), Tools::hour($transfer->fecha)
                );
                $movements[$key]->cantidad += $sign * $line->cantidad;
            }
        }

        return array_values(array_filter($movements, function (MovimientoStock $movement) {
            return abs($movement->cantidad) >= 0.000001;
        }));
    }

    protected static function isSupportedStockTransformation(DocTransformation $transformation): bool
    {
        return ($transformation->model1 === 'AlbaranCliente' && $transformation->model2 === 'FacturaCliente') ||
            ($transformation->model1 === 'AlbaranProveedor' && $transformation->model2 === 'FacturaProveedor');
    }

    protected static function movementKey($doc, string $codalmacen, string $referencia): string
    {
        return $doc->modelClassName() . '|' . $doc->id() . '|' . $codalmacen . '|' . $referencia;
    }

    protected static function newMovement(?int $idproducto, $doc, string $codalmacen, string $referencia, string $fecha, string $hora): MovimientoStock
    {
        $movement = new MovimientoStock();
        $movement->cantidad = 0.0;
        $movement->codalmacen = $codalmacen;
        $movement->docid = $doc->id();
        $movement->docmodel = $doc->modelClassName();
        $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . ($doc->codigo ?? $doc->id());
        $movement->fecha = $fecha;
        $movement->hora = $hora;
        $movement->idproducto = $idproducto;
        $movement->referencia = $referencia;
        $movement->saldo = 0.0;
        return $movement;
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

    /**
     * Ordena los movimientos nuevos, calcula sus saldos y los guarda. Se insertan en orden para que
     * sus ids respeten el mismo orden que usa compareMovements() con la misma fecha, hora y prioridad.
     *
     * @param MovimientoStock[] $movements
     */
    protected static function saveMovements(array $movements): bool
    {
        // usort es estable: con la misma fecha, hora y prioridad se mantiene el orden de cálculo
        usort($movements, [static::class, 'compareMovements']);
        static::accumulateSaldos($movements);

        foreach ($movements as $movement) {
            if (false === $movement->save()) {
                return false;
            }
        }

        return true;
    }

    /**
     * En una instalación nueva, las tablas se crean al usar sus modelos. En PostgreSQL, consultar una
     * tabla que no existe aborta toda la transacción del rebuild, así que lo comprobamos antes.
     */
    protected static function tableExists(string $tableName): bool
    {
        return (new DataBase())->tableExists($tableName);
    }
}

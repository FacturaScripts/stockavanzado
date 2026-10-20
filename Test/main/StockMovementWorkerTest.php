<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2026 Carlos García Gómez <carlos@facturascripts.com>
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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\CronJob\StockMovement;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

/**
 * Los movimientos de stock de documentos los reconstruye el worker RebuildStockMovements.
 */
final class StockMovementWorkerTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;
    use DefaultSettingsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testMovementsRebuiltByWorker(): void
    {
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $supplier = $this->getRandomSupplier();
        $this->assertTrue($supplier->save());

        // albarán de hoy con 10 unidades
        $albaranHoy = $this->createAlbaran($supplier, $warehouse, $product, 10);
        $this->runWorkQueue();
        $this->assertEquals(10, $this->getMovement($albaranHoy, $warehouse, $product)->saldo);

        // albarán anterior con 5 unidades en varias líneas de la misma referencia
        $albaranAnterior = $this->createAlbaran($supplier, $warehouse, $product, 2, date('d-m-Y', strtotime('-2 days')));
        $linea = $albaranAnterior->getNewProductLine($product->referencia);
        $linea->cantidad = 3;
        $this->assertTrue($linea->save());

        // el stock lo actualiza el core en el momento, los movimientos quedan pendientes
        $this->assertEquals(15, $this->getStock($warehouse, $product)->cantidad);
        $this->assertFalse($this->hasMovement($albaranAnterior, $warehouse, $product));
        $this->assertEquals(10, $this->getMovement($albaranHoy, $warehouse, $product)->saldo);

        // las líneas del mismo producto generan un único evento pendiente
        $this->assertEquals(1, $this->countPendingEvents($product));

        // el worker reconstruye los movimientos del producto
        $this->runWorkQueue();
        $this->assertEquals(0, $this->countPendingEvents($product));
        $movement = $this->getMovement($albaranAnterior, $warehouse, $product);
        $this->assertEquals(5, $movement->cantidad);
        $this->assertEquals(5, $movement->saldo);
        $this->assertEquals(15, $this->getMovement($albaranHoy, $warehouse, $product)->saldo);

        // tras procesarlo, un nuevo cambio del producto vuelve a encolarse
        $linea->cantidad = 4;
        $this->assertTrue($linea->save());
        $this->assertEquals(1, $this->countPendingEvents($product));
        $this->runWorkQueue();
        $this->assertEquals(6, $this->getMovement($albaranAnterior, $warehouse, $product)->saldo);
        $this->assertEquals(16, $this->getMovement($albaranHoy, $warehouse, $product)->saldo);

        // eliminamos
        $this->assertTrue($albaranAnterior->delete());
        $this->assertTrue($albaranHoy->delete());
        $this->runWorkQueue();
        $this->assertFalse($this->hasMovement($albaranHoy, $warehouse, $product));
        $this->assertTrue($supplier->delete());
        $this->assertTrue($supplier->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCountingWithPendingMovements(): void
    {
        $warehouse1 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse1->save());

        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $supplier = $this->getRandomSupplier();
        $this->assertTrue($supplier->save());

        // en el almacén 2 entra un albarán de hoy y después otro anterior, sin ejecutar la cola
        $albaranHoy = $this->createAlbaran($supplier, $warehouse2, $product, 10);
        $this->runWorkQueue();
        $albaranAnterior = $this->createAlbaran($supplier, $warehouse2, $product, 5, date('d-m-Y', strtotime('-2 days')));
        $this->assertEquals(1, $this->countPendingEvents($product));

        // el conteo del almacén 1 sólo actualiza el stock contado
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse1->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save());
        $this->assertTrue($conteo->addLine($product->referencia, $product->idproducto, 7)->exists());
        $this->assertTrue($conteo->updateStock());

        // el stock del almacén 2 no se deduce de los movimientos pendientes
        $this->assertEquals(7, $this->getStock($warehouse1, $product)->cantidad);
        $this->assertEquals(15, $this->getStock($warehouse2, $product)->cantidad);

        // el conteo encola la reconstrucción del producto, que completa los movimientos pendientes
        $this->runWorkQueue();
        $this->assertEquals(15, $this->getMovement($albaranHoy, $warehouse2, $product)->saldo);
        $this->assertEquals(7, $this->getStock($warehouse1, $product)->cantidad);
        $this->assertEquals(15, $this->getStock($warehouse2, $product)->cantidad);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($albaranAnterior->delete());
        $this->assertTrue($albaranHoy->delete());
        $this->runWorkQueue();
        $this->assertTrue($supplier->delete());
        $this->assertTrue($supplier->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse1->delete());
        $this->assertTrue($warehouse2->delete());
    }

    public function testLegacyUpdateEventRebuildsMovements(): void
    {
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $supplier = $this->getRandomSupplier();
        $this->assertTrue($supplier->save());

        $albaran = $this->createAlbaran($supplier, $warehouse, $product, 4);
        $this->runWorkQueue();

        // borramos el movimiento para comprobar que el evento antiguo lo vuelve a crear
        $this->assertTrue($this->getMovement($albaran, $warehouse, $product)->delete());
        $this->assertFalse($this->hasMovement($albaran, $warehouse, $product));

        // un evento antiguo de recálculo de saldos que quedó en cola lo procesa RebuildStockMovements
        $this->assertTrue(WorkQueue::send('Model.Producto.updateStockMovements', (string)$product->idproducto));
        $this->runWorkQueue();

        $movement = $this->getMovement($albaran, $warehouse, $product);
        $this->assertEquals(4, $movement->cantidad);
        $this->assertEquals(4, $movement->saldo);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->runWorkQueue();
        $this->assertTrue($supplier->delete());
        $this->assertTrue($supplier->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testRebuildAllKeepsMovementsAndSkipsInactiveProducts(): void
    {
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        $supplier = $this->getRandomSupplier();
        $this->assertTrue($supplier->save());

        // dos productos con albarán y uno sin ninguna actividad de stock
        $product1 = $this->getRandomProduct();
        $this->assertTrue($product1->save());
        $product2 = $this->getRandomProduct();
        $this->assertTrue($product2->save());
        $inactive = $this->getRandomProduct();
        $this->assertTrue($inactive->save());

        $albaran1 = $this->createAlbaran($supplier, $warehouse, $product1, 4);
        $albaran2 = $this->createAlbaran($supplier, $warehouse, $product2, 2);
        $this->runWorkQueue();

        // borramos el movimiento del primero para comprobar que la reconstrucción lo vuelve a crear
        $this->assertTrue($this->getMovement($albaran1, $warehouse, $product1)->delete());

        // el cron sólo encola el recorrido por páginas: no borra los movimientos existentes
        StockMovement::echoMode(CronJobClass::ECHO_MODE_LOG);
        StockMovement::run();
        $this->assertTrue($this->hasMovement($albaran2, $warehouse, $product2));
        $this->assertFalse($this->hasMovement($albaran1, $warehouse, $product1));

        // al procesar la cola se reconstruyen los productos con actividad
        $this->runWorkQueue();
        $this->assertEquals(4, $this->getMovement($albaran1, $warehouse, $product1)->saldo);
        $this->assertEquals(2, $this->getMovement($albaran2, $warehouse, $product2)->saldo);
        $this->assertEquals(0, $this->countPendingEvents($product1));
        $this->assertEquals(0, $this->countPendingRebuildAll());

        // el producto sin actividad no se ha encolado
        $this->assertEquals(0, WorkEvent::count([
            Where::eq('name', 'Model.Producto.rebuildStockMovements'),
            Where::eq('value', $inactive->idproducto)
        ]));

        // eliminamos
        $this->assertTrue($albaran1->delete());
        $this->assertTrue($albaran2->delete());
        $this->runWorkQueue();
        $this->assertTrue($supplier->delete());
        $this->assertTrue($supplier->getDefaultAddress()->delete());
        $this->assertTrue($product1->delete());
        $this->assertTrue($product2->delete());
        $this->assertTrue($inactive->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testRebuildAllDoesNotDuplicateEvents(): void
    {
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $supplier = $this->getRandomSupplier();
        $this->assertTrue($supplier->save());

        $albaran = $this->createAlbaran($supplier, $warehouse, $product, 3);
        $this->runWorkQueue();

        // el producto ya tiene una reconstrucción pendiente, con otros parámetros
        StockMovementManager::queueProductRebuild($product->idproducto, true);
        $this->assertEquals(1, $this->countPendingEvents($product));

        // la página empieza en este producto y no vuelve a encolarlo
        $this->assertEquals($product->idproducto, StockMovementManager::queueRebuildPage($product->idproducto - 1, 1));
        $this->assertEquals(1, $this->countPendingEvents($product));

        // lanzar el cron dos veces deja un solo recorrido pendiente
        StockMovement::echoMode(CronJobClass::ECHO_MODE_LOG);
        StockMovement::run();
        StockMovement::run();
        $this->assertEquals(1, $this->countPendingRebuildAll());

        $this->runWorkQueue();
        $this->assertEquals(0, $this->countPendingEvents($product));
        $this->assertEquals(0, $this->countPendingRebuildAll());
        $this->assertEquals(3, $this->getMovement($albaran, $warehouse, $product)->saldo);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->runWorkQueue();
        $this->assertTrue($supplier->delete());
        $this->assertTrue($supplier->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }

    private function countPendingRebuildAll(): int
    {
        return WorkEvent::count([
            Where::eq('done', false),
            Where::eq('name', 'StockAvanzado.rebuildAllStockMovements')
        ]);
    }

    private function countPendingEvents(Producto $product): int
    {
        return WorkEvent::count([
            Where::eq('done', false),
            Where::eq('name', 'Model.Producto.rebuildStockMovements'),
            Where::eq('value', $product->idproducto)
        ]);
    }

    private function createAlbaran(Proveedor $supplier, Almacen $warehouse, Producto $product, float $quantity, ?string $fecha = null): AlbaranProveedor
    {
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($supplier));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        if ($fecha) {
            $this->assertTrue($albaran->setDate($fecha, '12:00:00'));
        }
        $this->assertTrue($albaran->save());

        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = $quantity;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        return $albaran;
    }

    private function getMovement(AlbaranProveedor $albaran, Almacen $warehouse, Producto $product): MovimientoStock
    {
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ]));
        return $movement;
    }

    private function hasMovement(AlbaranProveedor $albaran, Almacen $warehouse, Producto $product): bool
    {
        $movement = new MovimientoStock();
        return $movement->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ]);
    }

    private function getStock(Almacen $warehouse, Producto $product): Stock
    {
        $stock = new Stock();
        $this->assertTrue($stock->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));
        return $stock;
    }

    private function runWorkQueue(): void
    {
        while (WorkQueue::run()) {
        }
    }
}

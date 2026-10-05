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
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

/**
 * Los saldos de los movimientos de documentos se recalculan en el worker UpdateStockMovements.
 */
final class StockSaldosWorkerTest extends TestCase
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

    public function testSaldosRecalculatedByWorker(): void
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

        // albarán anterior con 5 unidades y varias líneas de la misma referencia
        $albaranAnterior = $this->createAlbaran($supplier, $warehouse, $product, 2, date('d-m-Y', strtotime('-2 days')));
        $linea = $albaranAnterior->getNewProductLine($product->referencia);
        $linea->cantidad = 3;
        $this->assertTrue($linea->save());

        // el movimiento anterior tiene su saldo provisional, pero el posterior aún no se ha recalculado
        $this->assertEquals(5, $this->getMovement($albaranAnterior, $warehouse, $product)->saldo);
        $this->assertEquals(10, $this->getMovement($albaranHoy, $warehouse, $product)->saldo);

        // las líneas del mismo producto generan un único evento pendiente
        $this->assertEquals(1, $this->countPendingEvents($product));

        // el worker recalcula el histórico
        $this->runWorkQueue();
        $this->assertEquals(0, $this->countPendingEvents($product));
        $this->assertEquals(5, $this->getMovement($albaranAnterior, $warehouse, $product)->saldo);
        $this->assertEquals(15, $this->getMovement($albaranHoy, $warehouse, $product)->saldo);

        // eliminamos
        $this->assertTrue($albaranAnterior->delete());
        $this->assertTrue($albaranHoy->delete());
        $this->runWorkQueue();
        $this->assertTrue($supplier->delete());
        $this->assertTrue($supplier->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCountingWithPendingSaldos(): void
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

        // el conteo del almacén 1 reconstruye el stock de todo el producto
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse1->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save());
        $this->assertTrue($conteo->addLine($product->referencia, $product->idproducto, 7)->exists());
        $this->assertTrue($conteo->updateStock());

        // el stock del almacén 2 debe tener en cuenta el albarán anterior
        $this->assertEquals(7, $this->getStock($warehouse1, $product)->cantidad);
        $this->assertEquals(15, $this->getStock($warehouse2, $product)->cantidad);
        $this->assertEquals(15, $this->getMovement($albaranHoy, $warehouse2, $product)->saldo);

        // eliminamos
        $this->runWorkQueue();
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

    protected function tearDown(): void
    {
        $this->logErrors();
    }

    private function countPendingEvents(Producto $product): int
    {
        return WorkEvent::count([
            Where::eq('done', false),
            Where::eq('name', 'Model.Producto.updateStockMovements'),
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

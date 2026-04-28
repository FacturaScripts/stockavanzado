<?php
/**
 * Test for StockValoradoHistorico
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Stock as DinStock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use FacturaScripts\Plugins\StockAvanzado\CronJob\StockValue;
use FacturaScripts\Plugins\StockAvanzado\Model\StockValoradoHistorico;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\Tools;
use PHPUnit\Framework\TestCase;

final class StockValoradoHistoricoTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testHistoricSavedByCron(): void
    {
        // crear almacén
        $warehouse = new Almacen();
        $warehouse->nombre = 'Warehouse ' . mt_rand(1, 99);
        $this->assertTrue($warehouse->save());

        // crear producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // variante
        $variant = $product->getVariants()[0];
        $variant->coste = 10.0;
        $variant->precio = 20.0;
        $this->assertTrue($variant->save());

        // stock
        $stock = new DinStock();
        $stock->cantidad = 10;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save());

        // ejecutar cron (que debe guardar el histórico)
        StockValue::run($warehouse->codalmacen);

        // comprobar histórico
        $hist = new StockValoradoHistorico();
        $where = [Where::eq('codalmacen', $warehouse->codalmacen), Where::eq('fecha', Tools::date())];
        $this->assertTrue($hist->loadWhere($where));
        $this->assertEquals(100.0, (float)$hist->total_coste);
        $this->assertEquals(200.0, (float)$hist->total_precio);

        // cleanup
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testUniqueConstraint(): void
    {
        // crear almacén
        $warehouse = new Almacen();
        $warehouse->nombre = 'Test Warehouse ' . mt_rand(1, 99);
        $this->assertTrue($warehouse->save());

        // crear primer histórico
        $hist1 = new StockValoradoHistorico();
        $hist1->codalmacen = $warehouse->codalmacen;
        $hist1->fecha = Tools::date();
        $hist1->total_coste = 100.0;
        $hist1->total_precio = 200.0;
        $this->assertTrue($hist1->save());

        // intentar crear segundo histórico mismo almacén y fecha (debe fallar por constraint único)
        $hist2 = new StockValoradoHistorico();
        $hist2->codalmacen = $warehouse->codalmacen;
        $hist2->fecha = Tools::date();
        $hist2->total_coste = 150.0;
        $hist2->total_precio = 300.0;
        $this->assertFalse($hist2->save());

        // cleanup
        $this->assertTrue($warehouse->delete());
    }

    public function testModelFields(): void
    {
        $model = new StockValoradoHistorico();

        // verificar campos por defecto
        $this->assertEquals(0.0, $model->total_coste);
        $this->assertEquals(0.0, $model->total_precio);
        $this->assertEquals('stock_valorado_historico', $model::tableName());
    }
}
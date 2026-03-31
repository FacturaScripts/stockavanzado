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
        $where = [Where::column('codalmacen', $warehouse->codalmacen), Where::eq('fecha', Tools::date())];
        $this->assertTrue($hist->loadWhere($where));
        $this->assertEquals(100.0, (float)$hist->total_coste);
        $this->assertEquals(200.0, (float)$hist->total_precio);

        // comprobar detalles JSON
        $this->assertNotEmpty($hist->detalles);
        $details = json_decode($hist->detalles, true);
        $this->assertIsArray($details);
        $this->assertCount(1, $details);
        $line = $details[0];
        $this->assertEquals($product->referencia, $line['referencia']);
        $this->assertEquals(10.0, (float)$line['cantidad']);
        $this->assertEquals(10.0, (float)$line['coste']);
        $this->assertEquals(20.0, (float)$line['precio']);
        $this->assertEquals(100.0, (float)$line['valor_coste']);
        $this->assertEquals(200.0, (float)$line['valor_precio']);

        // cleanup
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }
}

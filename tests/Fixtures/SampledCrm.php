<?php

namespace D3Creative\Canary\Tests\Fixtures;

/**
 * A FakeCrm whose form has a field the sample can't fill, like lbf-com's datasheet
 * form, which only accepts a real product ID.
 */
class SampledCrm extends FakeCrm
{
    public function canarySample(string $form): array
    {
        return ['product_id' => 'product-1'];
    }
}

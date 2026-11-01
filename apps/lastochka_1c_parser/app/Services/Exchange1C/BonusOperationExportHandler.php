<?php

namespace App\Services\Exchange1C;

use App\Models\BonusOperation;

class BonusOperationExportHandler
{
    /** @param iterable<BonusOperation> $ops */
    public function buildXml(iterable $ops): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('КоммерческаяИнформация');
        $root->setAttribute('ВерсияСхемы', '2.03');
        $root->setAttribute('ДатаФормирования', now()->format('Y-m-d\TH:i:s'));
        $dom->appendChild($root);

        foreach ($ops as $op) {
            $doc = $dom->createElement('Документ');
            $this->appendElement($dom, $doc, 'Ид', (string) $op->operation_id);
            $this->appendElement($dom, $doc, 'ХозОперация', 'Бонусная операция');

            $requisites = $dom->createElement('ЗначенияРеквизитов');
            $this->appendRequisite($dom, $requisites, 'ИД операции', (string) $op->operation_id);
            $this->appendRequisite($dom, $requisites, 'ИД лояльности', (string) $op->loyalty_id);
            $this->appendRequisite($dom, $requisites, 'Тип', (string) $op->type);
            $this->appendRequisite($dom, $requisites, 'Баллы', (string) (int) $op->amount);
            $this->appendRequisite($dom, $requisites, 'Источник', (string) $op->source);
            if ($op->order_external_id) {
                $this->appendRequisite($dom, $requisites, 'ИД заказа', (string) $op->order_external_id);
            }
            $doc->appendChild($requisites);

            $root->appendChild($doc);
        }

        return $dom->saveXML();
    }

    private function appendRequisite(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $req = $dom->createElement('ЗначениеРеквизита');
        $this->appendElement($dom, $req, 'Наименование', $name);
        $this->appendElement($dom, $req, 'Значение', $value);
        $parent->appendChild($req);
    }

    private function appendElement(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($dom->createElement($name, htmlspecialchars($value, ENT_XML1)));
    }
}

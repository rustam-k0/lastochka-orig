<?php

namespace App\Services\Exchange1C;

use App\Models\Contragent;

class ContragentExportHandler
{
    /** @param iterable<Contragent> $contragents */
    public function buildXml(iterable $contragents): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('КоммерческаяИнформация');
        $root->setAttribute('ВерсияСхемы', '2.03');
        $root->setAttribute('ДатаФормирования', now()->format('Y-m-d\TH:i:s'));
        $dom->appendChild($root);

        $list = $dom->createElement('Контрагенты');

        foreach ($contragents as $c) {
            $node = $dom->createElement('Контрагент');
            $this->appendElement($dom, $node, 'Ид', (string) ($c->external_id ?: 'loyalty-'.$c->loyalty_id));
            $this->appendElement($dom, $node, 'Наименование', (string) $c->name);
            $this->appendElement($dom, $node, 'Роль', 'Покупатель');
            if ($c->phone) {
                $this->appendElement($dom, $node, 'Телефон', (string) $c->phone);
            }

            $requisites = $dom->createElement('ЗначенияРеквизитов');
            $this->appendRequisite($dom, $requisites, 'ИД лояльности', (string) $c->loyalty_id);
            $this->appendRequisite($dom, $requisites, 'Баланс баллов', (string) (int) $c->bonus_balance);
            if ($c->card_number) {
                $this->appendRequisite($dom, $requisites, 'Номер карты', (string) $c->card_number);
            }
            $node->appendChild($requisites);

            $list->appendChild($node);
        }

        $root->appendChild($list);

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
        $element = $dom->createElement($name, htmlspecialchars($value, ENT_XML1));
        $parent->appendChild($element);
    }
}

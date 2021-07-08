<?php

class ImporTrello
{
    private $apiUser;
    private $apiPassword;
    private $apiUrl;
    private $directory;

    private $boardUrl;
    private $labelUrl;
    private $stackUrl;
    private $cardUrl;
    private $cardLabelUrl;

    private $newboardId;
    private $labels = [];
    private $stacks = [];

    public function __construct($directory)
    {
        $this->directory = rtrim($directory, '/');
        $data = json_decode(file_get_contents('config.json'), true);
        $this->apiUrl = $data['url'];
        $this->apiUser = $data['user'];
        $this->apiPassword = $data['password'];

        $this->boardUrl = $this->apiUrl . 'boards';
        $this->labelUrl = $this->boardUrl . '/%s/labels';
        $this->stackUrl = $this->boardUrl . '/%s/stacks';
        $this->cardUrl = $this->boardUrl . '/%s/stacks/%s/cards';
        $this->cardLabelUrl = $this->boardUrl . '/%s/stacks/%s/cards/%s/assignLabel';
    }

    private function request($api_data, $api_url, $method = 'POST')
    {
        $opts = [
            'http' => [
                'method' => $method,
                'header' =>
                    "Content-Type: application/json\r\n" .
                    'Authorization: Basic ' . base64_encode($this->apiUser . ':' . $this->apiPassword) . "\r\n",
                'content' => json_encode($api_data)
            ]
        ];

        $context = stream_context_create($opts);

        $response = file_get_contents($api_url, false, $context);
        if ($response) {
            $response = json_decode($response, true);
            if (!empty($api_data['title'])) {
                echo "Imported successfully \"{$api_data['title']}\"\n";
            }
            return $response;
        }
        echo "Importing failed: \"{$api_data['title']}\"\n";
        print_r($response);
        $header = $this->parseHeaders($http_response_header);
        switch ($header['response_code']) {
            case 400:
                echo "Bad request. Parameter missing?\n";
                break;
            case 403:
                echo "Permission denied, check credentials.\n";
                break;
            case 404:
                echo "Server not found.\n";
                break;
            default:
                echo "Something weird happened.\n";
        }
        return '';
    }

    public function parseHeaders($headers)
    {
        $head = [];
        foreach ($headers as $value) {
            $keyValue = explode(':', $value, 2);
            if (isset($keyValue[1])) {
                $head[trim($keyValue[0])] = trim($keyValue[1]);
            } else {
                $head[] = $value;
                if (preg_match("#HTTP/[0-9\.]+\s+([0-9]+)#", $value, $out)) {
                    $head['reponse_code'] = intval($out[1]);
                }
            }
        }
        return $head;
    }

    private function checklist_item($item)
    {
        if (($item['state'] == 'incomplete')) {
            $string_start = '- [ ]';
        } else {
            $string_start = '- [x]';
        }
        $check_item_string = $string_start . ' ' . $item['name'] . "\n";
        return $check_item_string;
    }

    function formulate_checklist_text($checklist)
    {
        $checklist_string = "\n\n## {$checklist['name']}\n";
        foreach ($checklist['checkItems'] as $item) {
            $checklist_item_string = $this->checklist_item($item);
            $checklist_string = $checklist_string . "\n" . $checklist_item_string;
        }
        return $checklist_string;
    }

    private function translateColor($color)
    {
        switch ($color) {
            case 'red':
                return 'ff0000';
            case 'yellow':
                return 'ffff00';
            case 'orange':
                return 'ff6600';
            case 'green':
                return '00ff00';
            case 'purple':
                return '9900ff';
            case 'blue':
                return '0000ff';
            case 'sky':
                return '00ccff';
            case 'lime':
                return '00ff99';
            case 'pink':
                return 'ff66cc';
            case 'black':
                return '000000';
            default:
                return 'ffffff';
        }
    }

    public function import()
    {
        foreach (glob($this->directory . '/*.json') as $filename) {
            $this->data = json_decode(file_get_contents($filename), true);
            echo 'Processing file: ' . basename($filename) . "\n";

            $this->importBoard();
            $this->importLabels();
            $this->importStacks();
            $this->importCards();
        }
    }

    /**
     * Add board to Deck and retrieve the new board id
     */
    public function importBoard()
    {
        $trelloBoardName = $this->data['name'];
        $boardData = ['title' => $trelloBoardName, 'color' => '0800fd'];
        $this->newboardId = $this->request($boardData, $this->boardUrl)['id'];
    }

    public function importLabels()
    {
        # Import labels
        echo "Importing labels...\n";
        $this->labels = [];
        foreach ($this->data['labels'] as $label) {
            if (empty($label['name'])) {
                $labelTitle = 'Unnamed ' . $label['color'] . ' label';
            } else {
                $labelTitle = $label['name'];
            }
            $labelData = [
                'title' => $labelTitle,
                'color' => $this->translateColor($label['color'])
            ];
            $url = sprintf($this->labelUrl, $this->newboardId);
            $newLabelId = $this->request($labelData, $url)['id'];
            $this->labels[$label['id']] = $newLabelId;
        }
    }

    /**
     * Add stacks to the new board
     */
    public function importStacks()
    {
        echo "Importing stacks...\n";
        $url = sprintf($this->stackUrl, $this->newboardId);
        $this->stacks = [];
        foreach ($this->data['lists'] as $order => $list) {
            # If a list (= stack in Deck) is archived, skips to the next one.
            if ($list['closed']) {
                echo 'List ' . $list['name'] . " is archived, skipping to the next one...\n";
                continue;
            }
            $stackData = [
                'title' => $list['name'],
                'order' => $order + 1
            ];
            $this->stacks[$list['id']] = $this->request($stackData, $url)['id'];
        }
    }

    /**
     * Go through the cards and assign them to the correct lists (= stacks in Deck)
     */
    public function importCards()
    {
        # Save checklist content into a dictionary (_should_ work even if a card has multiple checklists
        foreach ($this->data['checklists'] as $checklist) {
            $checklists[$checklist['idCard']][$checklist['id']] = $this->formulate_checklist_text($checklist);
        }
        $this->data['checklists'] = $checklists;

        echo "Importing cards...\n";
        foreach ($this->data['cards'] as $card) {
            # Check whether a card is archived, if true, skipping to the next card
            if ($card['closed']) {
                echo 'Card ' . $card['name'] . " is archived, skipping...\n";
                continue;
            }
            if ((count($card['idChecklists']) !== 0)) {
                foreach ($this->data['checklists'][$card['id']] as $checklist) {
                    $card['desc'] .= "\n" . $checklist;
                }
            }
            $cardData = [
                'title' => $card['name'],
                'type' => 'plain',
                'order' => $card['idShort'],
                'description' => $card['desc']
            ];
            if ($card['due']) {
                $cardData['duedate'] = DateTime::createFromFormat('Y-m-d\TH:i:s.000\Z', $card['due'])
                    ->format('Y-m-d H:i:s');
            }
            $url = sprintf($this->cardUrl, $this->newboardId, $this->stacks[$card['idList']]);
            $newCardData = $this->request($cardData, $url);
            if ($newCardData) {
                $this->associateCardToLabels($newCardData['id'], $card);
            }
        }
    }

    public function associateCardToLabels($cardId, $card)
    {
        $url = sprintf($this->cardLabelUrl, $this->newboardId, $this->stacks[$card['idList']], $cardId);
        foreach ($card['labels'] as $label) {
            $updateLabelData = [
                'labelId' => (int) $this->labels[$label['id']]
            ];
            $labelResponse = $this->request($updateLabelData, $url, 'PUT');
            if ($labelResponse) {
                echo "Assigning label failed to card [{$card['name']}]\n";
                print_r($labelResponse);
            } else {
                echo "Label assigned to card [{$card['name']}]\n";
            }
        }
    }
}

$import = new ImporTrello(__DIR__ . '/data/');
$import->import();

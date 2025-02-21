elseif ($method_id == 77) :
    $apiKey = $extra['api_key'] ?? null;
    $apiUrl = "https://tzsmmpay.com/api/payment/create";

    if (!$apiKey) {
        die(json_encode(["status" => false, "message" => "API Key is missing"]));
    }

    $final_amount = $amount;
    $txnid = substr(hash('sha256', mt_rand() . microtime()), 0, 20);

    $posted = [
        'api_key'     => $apiKey,
        'cus_name'    => $user['username'] ?? 'John Doe',
        'cus_email'   => $user['email'] ?? 'noemail@example.com',
        'cus_number'  => $txnid, 
        'amount'      => number_format($final_amount, 2, '.', ''),
        'currency'    => $extra['exchange_rate'] ?? 'USD',
        'success_url' => site_url('addfunds?success=true'),
        'cancel_url'  => site_url('addfunds?cancel=true'),
        'callback_url'=> site_url('payment/tzsmmpay'),
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($posted),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        die(json_encode(["status" => false, "message" => "cURL Error: " . $err]));
    }

    $result = json_decode($response, true);

    if (!isset($result['success']) || !$result['success']) {
        die(json_encode(["status" => false, "messages" => $result['messages'] ?? json_encode($result)]));
    }

    $order_id = $txnid;
    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");

    $insertSuccess = $insert->execute([
        "c_id" => $user['client_id'],
        "amount" => $amount,
        "code" => $txnid,
        "method" => $method_id,
        "date" => date("Y-m-d H:i:s"),
        "ip" => GetIP(),
        "extra" => $order_id
    ]);

    if (!$insertSuccess) {
        die(json_encode(["status" => false, "message" => "Database error: Unable to insert payment record"]));
    }

    if (!isset($result['payment_url']) || empty($result['payment_url'])) {
        die(json_encode(["status" => false, "message" => "Payment URL not received from API"]));
    }

    $payment_url = $result['payment_url'];

    echo '<div class="dimmer active" style="min-height: 400px;">
        <div class="loader"></div>
        <div class="dimmer-content">
            <center>
                <h2>Please do not refresh this page</h2>
            </center>
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin:auto;background:#fff;display:block;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
                <circle cx="50" cy="50" r="32" stroke-width="8" stroke="#e15b64" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round">
                    <animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
                </circle>
                <circle cx="50" cy="50" r="23" stroke-width="8" stroke="#f8b26a" stroke-dasharray="36.12831551628262 36.12831551628262" stroke-dashoffset="36.12831551628262" fill="none" stroke-linecap="round">
                    <animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
                </circle>
            </svg>
            <form action="' . htmlspecialchars($payment_url) . '" method="get" name="tzsmmPayForm" id="pay">
                <script type="text/javascript">
                    document.getElementById("pay").submit();
                </script>
            </form>
        </div>
    </div>';


if ($method_name == 'tzsmmpay') {
    $cus_number = $_REQUEST['cus_number'];

    if (empty($cus_number)) {
        $up_response = file_get_contents('php://input');
        $up_response_decode = json_decode($up_response, true);
        $cus_number = $up_response_decode['cus_number'];
    }

    if (empty($cus_number)) {
        die('Direct access is not allowed.');
    }

    $apiKey = trim($extras['api_key']);
    $trx_id = $_REQUEST['trx_id'];

    // Check if trx_id is already used
    $trxCheck = $conn->prepare("SELECT * FROM payments WHERE t_id = :trx_id");
    $trxCheck->execute(["trx_id" => $trx_id]);

    if ($trxCheck->rowCount()) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction ID already used.']);
        exit;
    }

    // Get payment details using cus_number
    $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra = :cus_number AND payment_status = 1");
    $paymentDetails->execute(["cus_number" => $cus_number]);

    if (!$paymentDetails->rowCount()) {
        echo json_encode(['status' => 'error', 'message' => 'Payment not found for this customer number.']);
        exit;
    }

    $paymentData = $paymentDetails->fetch(PDO::FETCH_ASSOC);

    $url = "https://tzsmmpay.com/api/payment/verify?api_key={$apiKey}&trx_id={$trx_id}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_status !== 200) {
        echo json_encode(['status' => 'error', 'message' => 'Error verifying transaction.']);
        exit;
    }

    $result = json_decode($response, true);

    if (isset($result['status']) && $result['status'] == 'Completed') {
        $stmt = $conn->prepare("SELECT balance FROM clients WHERE client_id = :id");
        $stmt->execute(["id" => $paymentData["client_id"]]);
        $userBalance = floatval($stmt->fetchColumn());

        $paidAmount = floatval($paymentData["payment_amount"]);

        if ($paymentFee > 0) {
            $fee = ($paidAmount * ($paymentFee / 100));
            $paidAmount -= $fee;
        }

        if ($paymentBonusStartAmount != 0 && $paidAmount > $paymentBonusStartAmount) {
            $bonus = $paidAmount * ($paymentBonus / 100);
            $paidAmount += $bonus;
        }

        $paidAmount = $paidAmount;

        $conn->beginTransaction();

        // Update payment status & trx_id
        $updatePayment = $conn->prepare('UPDATE payments SET 
            client_balance = :balance,
            payment_status = :status, 
            payment_delivery = :delivery,
            t_id = :trx_id 
            WHERE payment_id = :id');
        $updatePayment->execute([
            'balance' => $userBalance,
            'status' => 3,
            'delivery' => 2,
            'trx_id' => $trx_id,
            'id' => $paymentData['payment_id']
        ]);

        // Update user balance
        $newBalance = $userBalance + $paidAmount;
        $updateClientBalance = $conn->prepare('UPDATE clients SET balance = :balance WHERE client_id = :id');
        $updateClientBalance->execute([
            "balance" => $newBalance,
            "id" => $paymentData["client_id"]
        ]);

        // Insert transaction report
        $insertReport = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date');
        $insertReport->execute([
            'c_id' => $paymentData['client_id'],
            'action' => 'New ' . $paidAmount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'],
            'ip' => GetIP(),
            'date' => date('Y-m-d H:i:s')
        ]);

        // Handle bonus payments if applicable
        if ($paymentBonusStartAmount != 0 && $paidAmount > $paymentBonusStartAmount) {
            $insertBonus = $conn->prepare("INSERT INTO payments SET 
                client_id = :client_id, 
                client_balance = :client_balance,
                payment_amount = :payment_amount, 
                payment_method = :payment_method, 
                payment_status = :payment_status,
                payment_delivery = :payment_delivery, 
                payment_note = :payment_note, 
                payment_create_date = :payment_create_date, 
                payment_extra = :payment_extra, 
                bonus = :bonus");
            $insertBonus->execute([
                'client_id' => $paymentData['client_id'],
                'client_balance' => $newBalance - $bonus,
                'payment_amount' => $bonus,
                'payment_method' => 1,
                'payment_status' => 3,
                'payment_delivery' => 2,
                'payment_note' => "Bonus added",
                'payment_create_date' => date('Y-m-d H:i:s'),
                'payment_extra' => "Bonus added for previous payment",
                'bonus' => 1
            ]);
        }

        if ($updatePayment && $updateClientBalance && $insertReport) {
            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Payment verified and balance updated.']);
        } else {
            $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Error updating payment.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Transaction verification failed.', 'response' => $result]);
    }
}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Dispute Letter</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 12pt;
            line-height: 1.6;
            color: #333;
            padding: 40px;
        }

        .header {
            margin-bottom: 30px;
        }

        .client-info {
            margin-bottom: 20px;
        }

        .client-info p {
            margin: 5px 0;
        }

        .letter-content {
            margin-bottom: 30px;
        }

        .letter-content p {
            margin-bottom: 15px;
            text-align: justify;
        }

        .disputed-items {
            margin: 20px 0;
        }

        .disputed-items ul {
            list-style-type: disc;
            margin-left: 30px;
        }

        .disputed-items li {
            margin: 10px 0;
        }

        .signature-section {
            margin-top: 50px;
        }

        .signature-line {
            margin-top: 50px;
            border-top: 1px solid #333;
            width: 300px;
            padding-top: 5px;
        }

        strong {
            font-weight: bold;
        }

        .date {
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="client-info">
            <p><strong>{{ $client->full_name }}</strong></p>
            <p>{{ $client->address }}</p>
            <p>{{ $client->city }}, {{ $client->state }} {{ $client->zip }}</p>
            @if($client->phone)
                <p>Phone: {{ $client->phone }}</p>
            @endif
            @if($client->email)
                <p>Email: {{ $client->email }}</p>
            @endif
        </div>

        <div class="date">
            <p>{{ now()->format('F d, Y') }}</p>
        </div>
    </div>

    <div class="letter-content">
        {!! $content !!}
    </div>

    <div class="signature-section">
        <p>Sincerely,</p>
        <div class="signature-line">
            <p>{{ $client->full_name }}</p>
        </div>
    </div>
</body>
</html>

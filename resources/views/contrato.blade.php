<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contrato de Trabajo</title>
    <style>
        body { font-family: 'Arial', sans-serif; font-size: 12px; line-height: 1.5; }
        .titulo { text-align: center; font-weight: bold; text-decoration: underline; margin-bottom: 20px; }
        .contenido { text-align: justify; }
        .firmas { margin-top: 50px; width: 100%; }
        .firma-box { width: 45%; display: inline-block; text-align: center; border-top: 1px solid #000; padding-top: 5px; }
        .espacio { width: 8%; display: inline-block; }
    </style>
</head>
<body>
    <div class="titulo">CONTRATO DE TRABAJO</div>

    <div class="contenido">
        <p>Conste por el presente documento el Contrato de Trabajo que celebran de una parte, 
        <strong>EMPRESA INTEGRA S.A.C.</strong>, con RUC N° XXXXXXXXXXX, debidamente representada por su 
        Gerente General, Sr. NOMBRE DEL GERENTE, identificado con DNI N° XXXXXXXXX, 
        con domicilio en DIRECCIÓN DE LA EMPRESA, a quien en adelante se le denominará 
        EL EMPLEADOR; y de la otra parte, el Sr./Sra. <strong>{{ $nombre_completo }}</strong>, identificado 
        con <strong>{{ $tipo_documento }}</strong> N° <strong>{{ $num_documento }}</strong>, con dirección en <strong>{{ $direccion }}</strong>, a quien en 
        adelante se le denominará EL TRABAJADOR, en los términos y condiciones siguientes:</p>

        <p><strong>PRIMERA: Objeto del Contrato</strong><br>
        EL EMPLEADOR contrata los servicios de EL TRABAJADOR, quien se obliga a desempeñar 
        las funciones de CARGO DEL TRABAJADOR, de conformidad con las instrucciones 
        que reciba de su superior inmediato y de acuerdo a la naturaleza de sus labores.</p>

        <p><strong>SEGUNDA: Jornada de Trabajo</strong><br>
        La jornada de trabajo será de ocho (8) horas diarias o cuarenta y ocho (48) horas 
        semanales, de lunes a sábado, en el horario que se determine en el centro de trabajo.</p>

        <p><strong>TERCERA: Remuneración</strong><br>
        EL EMPLEADOR pagará a EL TRABAJADOR una remuneración mensual de <strong>S/. {{ number_format($salario, 2) }}</strong>, 
        que será abonada en la cuenta bancaria que EL TRABAJADOR indique.</p>

        <p><strong>CUARTA: Duración del Contrato</strong><br>
        El presente contrato tendrá una duración definida, iniciándose el <strong>{{ $fecha_inicio }}</strong> 
        y finalizando el <strong>{{ $fecha_fin }}</strong>.</p>

        <p><strong>QUINTA: Obligaciones de las Partes</strong><br>
        EL TRABAJADOR se compromete a cumplir con las normas internas de EL EMPLEADOR. EL EMPLEADOR, por su parte, se 
        compromete a brindar las condiciones necesarias para el adecuado desempeño de las funciones.</p>

        <p><strong>SEXTA: Terminación del Contrato</strong><br>
        El presente contrato podrá ser terminado por cualquiera de las partes, sin expresión 
        de causa, con un preaviso de treinta (30) días calendario.</p>

        <p>En fe de lo cual, las partes firman el presente contrato en dos ejemplares de igual 
        tenor y efecto, en la ciudad de LUGAR, a los {{ date('d/m/Y') }}.</p>
    </div>

    <div class="firmas">
        <div class="firma-box">EL EMPLEADOR</div>
        <div class="espacio"></div>
        <div class="firma-box">EL TRABAJADOR</div>
    </div>
</body>
</html>
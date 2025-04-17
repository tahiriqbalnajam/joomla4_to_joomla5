<?php
// --- Configuration ---
// Database connection details for Joomla 4
$joomla4Host = 'localhost';
$joomla4User = 'root'; 
$joomla4Pass = '';     
$joomla4Db   = 'joomla_employee'; 
$j4Prefix    = 'jos'; 

// Database connection details for Joomla 5
$joomla5Host = 'localhost';
$joomla5User = 'root'; 
$joomla5Pass = '';    
$joomla5Db   = 'joomla_final'; 
$j5Prefix    = 'renoi';   

// Default User ID for created_by/modified_by if original user doesn't exist in Joomla 5
// It's recommended to migrate users first or map them appropriately.
// Find a Super User ID in your Joomla 5 #__users table.
$defaultUserId = 826; // Replace with a valid Super User ID from Joomla 5

// Default Workflow Stage ID for 'Published' state
// Check your #__workflow_stages table in Joomla 5. It's often 1 for the basic workflow.
$defaultPublishedStageId = 1;

// --- End Configuration ---

// Get current timestamp for any missing date fields
$currentTime = date('Y-m-d H:i:s');
$nullDate = '0000-00-00 00:00:00'; // Joomla's representation of a null date

// Increase execution time and memory limit for potentially long script
ini_set('max_execution_time', 300); // 5 minutes
ini_set('memory_limit', '256M');

echo "Starting Joomla 4 to Joomla 5 Article Import...\n<br>";
echo "Joomla 4 Prefix: " . htmlspecialchars($j4Prefix) . "\n<br>";
echo "Joomla 5 Prefix: " . htmlspecialchars($j5Prefix) . "\n<br>";

try {
    // --- Database Connections ---
    $joomla4Conn = new PDO("mysql:host=$joomla4Host;dbname=$joomla4Db;charset=utf8", $joomla4User, $joomla4Pass);
    $joomla4Conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to Joomla 4 database ($joomla4Db) successfully.\n<br>";

    $joomla5Conn = new PDO("mysql:host=$joomla5Host;dbname=$joomla5Db;charset=utf8", $joomla5User, $joomla5Pass);
    $joomla5Conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to Joomla 5 database ($joomla5Db) successfully.\n<br>";

    // --- Get Target Table Structures (Optional but helpful for debugging) ---
    try {
        $j5ContentFieldsStmt = $joomla5Conn->query("SHOW COLUMNS FROM {$j5Prefix}_content");
        $j5ContentFields = $j5ContentFieldsStmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Joomla 5 Content Fields: " . implode(', ', $j5ContentFields) . "\n<br>";

        $j5CategoryFieldsStmt = $joomla5Conn->query("SHOW COLUMNS FROM {$j5Prefix}_categories");
        $j5CategoryFields = $j5CategoryFieldsStmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Joomla 5 Category Fields: " . implode(', ', $j5CategoryFields) . "\n<br>";

        // Check for workflow associations table
        $joomla5Conn->query("SHOW COLUMNS FROM {$j5Prefix}_workflow_associations");
        $workflowTableExists = true;
        echo "Joomla 5 Workflow Associations table ({$j5Prefix}_workflow_associations) found.\n<br>";

    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '_workflow_associations') !== false) {
             echo "Warning: Joomla 5 Workflow Associations table ({$j5Prefix}_workflow_associations) not found. Workflow steps will be skipped.\n<br>";
             $workflowTableExists = false;
        } else {
            echo "Error fetching Joomla 5 table structures: " . $e->getMessage() . "\n<br>";
            // Decide if you want to continue or exit
            throw $e; // Re-throw critical error
        }
    }

    // --- Fetch Articles from Joomla 4 ---
    $fetchArticlesQuery = "SELECT * FROM {$j4Prefix}_content";
    $fetchArticlesStmt = $joomla4Conn->query($fetchArticlesQuery);
    // Fetch row by row to conserve memory for large sites
    // $articles = $fetchArticlesStmt->fetchAll(PDO::FETCH_ASSOC); // Use fetch() in loop instead
    $totalArticlesStmt = $joomla4Conn->query("SELECT COUNT(*) FROM {$j4Prefix}_content");
    $totalArticles = $totalArticlesStmt->fetchColumn();
    echo "Found " . $totalArticles . " articles in Joomla 4 ({$j4Prefix}_content).\n<br>";

    $articleCount = 0;
    $categoryMap = []; // To store mapping of old category IDs to new ones
    $userMap = []; // Optional: Add user mapping if migrating users separately

    // --- Start Transaction on Joomla 5 DB ---
    $joomla5Conn->beginTransaction();
    echo "Starting transaction on Joomla 5 database.\n<br>";

    // Fetch articles one by one
    while ($article = $fetchArticlesStmt->fetch(PDO::FETCH_ASSOC)) {
        $oldArticleId = $article['id'];
        echo "Processing Article ID (J4): $oldArticleId - Title: " . htmlspecialchars($article['title']) . "\n<br>";

        // --- Handle Categories ---
        $oldCategoryId = $article['catid'];
        $newCategoryId = null;

        if (isset($categoryMap[$oldCategoryId])) {
            $newCategoryId = $categoryMap[$oldCategoryId];
            // echo "Using cached category mapping for Old Cat ID: $oldCategoryId -> New Cat ID: $newCategoryId\n<br>";
        } elseif ($oldCategoryId > 0) {
            // Fetch category details from Joomla 4
            $fetchCategoryQuery = "SELECT * FROM {$j4Prefix}_categories WHERE id = :id";
            $fetchCategoryStmt = $joomla4Conn->prepare($fetchCategoryQuery);
            $fetchCategoryStmt->execute([':id' => $oldCategoryId]);
            $category = $fetchCategoryStmt->fetch(PDO::FETCH_ASSOC);

            if ($category) {
                // Check if category exists in Joomla 5 by title and extension
                $checkCategoryQuery = "SELECT id FROM {$j5Prefix}_categories WHERE title = :title AND extension = :extension";
                $checkCategoryStmt = $joomla5Conn->prepare($checkCategoryQuery);
                $checkCategoryStmt->execute([
                    ':title' => $category['title'],
                    ':extension' => $category['extension'] ?? 'com_content' // Assume com_content if missing
                ]);
                $existingCategory = $checkCategoryStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingCategory) {
                    $newCategoryId = $existingCategory['id'];
                    // echo "Category '{$category['title']}' found in Joomla 5. New Cat ID: $newCategoryId\n<br>";
                } else {
                    // Insert category into Joomla 5
                    // echo "Category '{$category['title']}' not found in Joomla 5. Inserting...\n<br>";
                    $insertCategoryParams = [
                        // --- Start Modification ---
                        ':asset_id' => 0, // Set asset_id to 0 instead of NULL
                        // --- End Modification ---
                        ':title' => $category['title'],
                        ':alias' => $category['alias'] ?: str_replace(' ', '-', strtolower($category['title'])),
                        ':description' => $category['description'] ?? '',
                        ':published' => $category['published'] ?? 1, // Use published state from J4
                        ':access' => $category['access'] ?? 1,
                        ':params' => $category['params'] ?? '{}',
                        ':metadata' => $category['metadata'] ?? '{}',
                        ':created_user_id' => $category['created_user_id'] ?? $defaultUserId, // Map users if possible
                        ':created_time' => $category['created_time'] ?? $currentTime,
                        ':modified_user_id' => $category['modified_user_id'] ?? $defaultUserId, // Map users if possible
                        ':modified_time' => $category['modified_time'] ?? $currentTime,
                        ':hits' => $category['hits'] ?? 0,
                        ':language' => $category['language'] ?? '*',
                        ':version' => $category['version'] ?? 1,
                        ':parent_id' => $category['parent_id'] ?? 1,
                        ':level' => $category['level'] ?? 1,
                        ':path' => $category['path'] ?? ($category['alias'] ?: str_replace(' ', '-', strtolower($category['title']))),
                        ':extension' => $category['extension'] ?? 'com_content',
                        ':note' => $category['note'] ?? ''
                    ];

                    // Build dynamic query based on actual J5 category fields
                    $catInsertFields = [];
                    $catInsertPlaceholders = [];
                    $catExecuteParams = [];
                    foreach ($insertCategoryParams as $key => $value) {
                        $field = ltrim($key, ':');
                        if (in_array($field, $j5CategoryFields)) {
                            $catInsertFields[] = "`" . $field . "`";
                            $catInsertPlaceholders[] = $key;
                            $executeValue = $value;
                            if ($executeValue === $nullDate) { // Handle Joomla null date specifically
                                // If the field MUST have a value (like modified_time), use current time
                                if ($field === 'modified_time' || $field === 'created_time') {
                                     $executeValue = $currentTime;
                                } else {
                                     $executeValue = null; // Otherwise, allow NULL if column permits
                                }
                            } elseif (is_null($executeValue)) {
                                // Handle actual NULL values if needed, e.g., for required fields
                                if ($field === 'modified_time' || $field === 'created_time') {
                                     $executeValue = $currentTime;
                                }
                            }
                            // --- Start Modification ---
                            // Remove the specific check that forced asset_id to NULL
                            // The value (now 0 from $insertCategoryParams) will be used.
                            // --- End Modification ---
                            $catExecuteParams[$key] = $executeValue;
                        }
                    }

                    if (!empty($catInsertFields)) {
                        $insertCategoryQuery = "INSERT INTO {$j5Prefix}_categories (" . implode(', ', $catInsertFields) . ") VALUES (" . implode(', ', $catInsertPlaceholders) . ")";
                        $insertCategoryStmt = $joomla5Conn->prepare($insertCategoryQuery);
                        // Line 185 is likely here:
                        $insertCategoryStmt->execute($catExecuteParams);
                        $newCategoryId = $joomla5Conn->lastInsertId();
                        // echo "Category inserted successfully. New Cat ID: $newCategoryId\n<br>";
                    } else {
                         echo "Warning: Could not build category insert query for '{$category['title']}' - no matching fields found?\n<br>";
                         $newCategoryId = 1; // Default to Uncategorised or Root if insert fails
                    }
                }
                // Cache the mapping
                $categoryMap[$oldCategoryId] = $newCategoryId;
            } else {
                // echo "Warning: Category with ID $oldCategoryId not found in Joomla 4. Assigning article to default category (1).\n<br>";
                $newCategoryId = 1; // Default to 'Uncategorised'
                $categoryMap[$oldCategoryId] = $newCategoryId; // Cache this default
            }
        } else {
             // echo "Article has Cat ID 0 or invalid. Assigning article to default category (1).\n<br>";
             $newCategoryId = 1; // Default to 'Uncategorised'
        }

        // --- Prepare Article Data for Joomla 5 ---
        $j4_state = $article['state'] ?? 0;
        $j5_state = $j4_state; // Direct mapping: 1=published, 0=unpublished, 2=trashed, -2=archived (J3/4)
        // Adjust state if J5 uses different values or if mapping 'archived'
        // Example: Map J4 archived (-2) to J5 unpublished (0) or trashed (2)
        if ($j4_state == -2) {
            $j5_state = 0; // Or 2 if you want them trashed
        }


        $insertArticleParams = [
            // --- Start Modification ---
            ':asset_id' => 0, // Set asset_id to 0 instead of NULL
            // --- End Modification ---
            ':title' => $article['title'],
            ':alias' => $article['alias'] ?: str_replace(' ', '-', strtolower($article['title'])),
            ':introtext' => $article['introtext'] ?? '',
            ':fulltext' => $article['fulltext'] ?? '',
            ':state' => $j5_state, // Use mapped state
            ':catid' => $newCategoryId,
            ':created' => $article['created'] ?? $currentTime,
            ':created_by' => $article['created_by'] ?? $defaultUserId, // Map users if possible
            ':created_by_alias' => $article['created_by_alias'] ?? '',
            ':modified' => $article['modified'] ?? $currentTime,
            ':modified_by' => $article['modified_by'] ?? $defaultUserId, // Map users if possible
            ':checked_out' => 0,
            ':checked_out_time' => null, // Use NULL instead of nullDate string
            ':publish_up' => $article['publish_up'] ?? $currentTime,
            ':publish_down' => ($article['publish_down'] == $nullDate || empty($article['publish_down'])) ? null : $article['publish_down'],
            ':images' => $article['images'] ?? '{}',
            ':urls' => $article['urls'] ?? '{}',
            ':attribs' => $article['attribs'] ?? '{}',
            ':version' => $article['version'] ?? 1,
            ':ordering' => $article['ordering'] ?? 0,
            ':metakey' => $article['metakey'] ?? '',
            ':metadesc' => $article['metadesc'] ?? '',
            ':access' => $article['access'] ?? 1,
            ':hits' => $article['hits'] ?? 0,
            ':metadata' => $article['metadata'] ?? '{}',
            ':featured' => $article['featured'] ?? 0,
            ':language' => $article['language'] ?? '*',
            ':note' => $article['note'] ?? '',
            ':created_time' => $article['created'] ?? $currentTime // Often same as 'created'
        ];

        // --- Insert Article into Joomla 5 ---
        $artInsertFields = [];
        $artInsertPlaceholders = [];
        $artExecuteParams = [];
        foreach ($insertArticleParams as $key => $value) {
            $field = ltrim($key, ':');
            // Handle state vs published field difference if necessary (less common now)
            // if ($field === 'state' && !in_array('state', $j5ContentFields) && in_array('published', $j5ContentFields)) {
            //      $field = 'published'; $key = ':published';
            // }

            if (in_array($field, $j5ContentFields)) {
                $artInsertFields[] = "`" . $field . "`";
                $artInsertPlaceholders[] = $key;
                $executeValue = $value;
                if ($executeValue === $nullDate) {
                    // Handle required date fields specifically if needed
                     if ($field === 'modified_time' || $field === 'created_time' || $field === 'publish_up') {
                         $executeValue = $currentTime;
                     } elseif ($field === 'checked_out_time' || $field === 'publish_down') {
                         $executeValue = null; // These can often be NULL
                     } else {
                         $executeValue = null; // Default to NULL for other fields if column allows
                     }
                }
                 // --- Start Modification ---
                 // Remove the specific check for :asset_id that forced it to NULL
                 // The value (which is now 0 from $insertArticleParams) will be used directly.
                 // --- End Modification ---
                $artExecuteParams[$key] = $executeValue;
            }
        }

        if (!empty($artInsertFields)) {
            $insertArticleQuery = "INSERT INTO {$j5Prefix}_content (" . implode(', ', $artInsertFields) . ") VALUES (" . implode(', ', $artInsertPlaceholders) . ")";
            $insertArticleStmt = $joomla5Conn->prepare($insertArticleQuery);
            // Line 284 is here:
            $insertArticleStmt->execute($artExecuteParams);
            $newArticleId = $joomla5Conn->lastInsertId();
            $articleIdMap[$oldArticleId] = $newArticleId;
            // echo "Article inserted successfully. Old ID: $oldArticleId -> New ID: $newArticleId\n<br>";

            // --- Handle Workflow Association (Crucial for J4/J5) ---
            if ($workflowTableExists && $newArticleId > 0) {
                try {
                    // Determine the stage based on the article's state
                    $stageId = null;
                    if ($j5_state == 1) { // Published
                        $stageId = $defaultPublishedStageId; // Use configured published stage ID
                    } elseif ($j5_state == 0) { // Unpublished
                        // Find the default 'unpublished' stage ID if needed, or skip association
                        // Example: Query #__workflow_stages for unpublished stage ID
                    } // Add logic for trashed, archived if necessary

                    if ($stageId !== null) {
                        $assocQuery = "INSERT INTO {$j5Prefix}_workflow_associations (item_id, stage_id, extension)
                                       VALUES (:item_id, :stage_id, :extension)
                                       ON DUPLICATE KEY UPDATE stage_id = :stage_id"; // Update if exists
                        $assocStmt = $joomla5Conn->prepare($assocQuery);
                        $assocStmt->execute([
                            ':item_id' => $newArticleId,
                            ':stage_id' => $stageId,
                            ':extension' => 'com_content.article'
                        ]);
                        // echo "Workflow association added/updated for New Article ID: $newArticleId to Stage ID: $stageId\n<br>";
                    }
                } catch (PDOException $e) {
                     echo "Warning: Could not add workflow association for New Article ID $newArticleId: " . $e->getMessage() . "\n<br>";
                }
            }

            // --- TODO: Handle Tags (Add logic here if needed) ---


            $articleCount++;
        } else {
            echo "Error: Could not build article insert query for Old Article ID: $oldArticleId - no matching fields found?\n<br>";
        }
        // echo "--------------------\n<br>";
        if ($articleCount % 100 == 0) {
             echo "Processed $articleCount articles...\n<br>";
             // Optional: Commit periodically for very large imports to free resources,
             // but this breaks the all-or-nothing transaction guarantee.
             // $joomla5Conn->commit(); $joomla5Conn->beginTransaction();
        }

    } // End while fetch article loop
    

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // +++ START MENU IMPORT SECTION ++++++++++++++++++++++++++++++++
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    echo "<hr><h2>Starting Menu Import...</h2>";

    $menuItemCount = 0;
    $menuTypeCount = 0;
    $menuIdMap = [0 => 1]; // Map old parent_id 0 (root) to new parent_id 1 (Joomla's default root)
    $componentMap = []; // To store component name -> extension_id mapping

    // --- Pre-fetch Joomla 5 Component IDs ---
    try {
        $extQuery = "SELECT extension_id, element FROM {$j5Prefix}_extensions WHERE type = 'component'";
        $extStmt = $joomla5Conn->query($extQuery);
        while ($row = $extStmt->fetch(PDO::FETCH_ASSOC)) {
            $componentMap[$row['element']] = $row['extension_id'];
        }
        echo "Fetched Joomla 5 component IDs.\n<br>";
    } catch (PDOException $e) {
        echo "Error fetching Joomla 5 component IDs: " . $e->getMessage() . ". Menu item component IDs might be incorrect.\n<br>";
    }

    // --- Get Target Menu Table Structures ---
    try {
        $j5MenuFieldsStmt = $joomla5Conn->query("SHOW COLUMNS FROM {$j5Prefix}_menu");
        $j5MenuFields = $j5MenuFieldsStmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Joomla 5 Menu Fields: " . implode(', ', $j5MenuFields) . "\n<br>";

        $j5MenuTypeFieldsStmt = $joomla5Conn->query("SHOW COLUMNS FROM {$j5Prefix}_menu_types");
        $j5MenuTypeFields = $j5MenuTypeFieldsStmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Joomla 5 Menu Type Fields: " . implode(', ', $j5MenuTypeFields) . "\n<br>";
    } catch (PDOException $e) {
        echo "Error fetching Joomla 5 menu table structures: " . $e->getMessage() . "\n<br>";
        throw $e; // Stop if we can't get menu structure
    }


    // --- 1. Import Menu Types ---
    echo "<h3>Importing Menu Types...</h3>";
    try {
        $fetchMenuTypesQuery = "SELECT * FROM {$j4Prefix}_menu_types";
        $fetchMenuTypesStmt = $joomla4Conn->query($fetchMenuTypesQuery);

        while ($menuType = $fetchMenuTypesStmt->fetch(PDO::FETCH_ASSOC)) {
            $oldMenuType = $menuType['menutype'];
            echo "Processing Menu Type: " . htmlspecialchars($oldMenuType) . "\n<br>";

            // Check if menu type exists in Joomla 5
            $checkMenuTypeQuery = "SELECT COUNT(*) FROM {$j5Prefix}_menu_types WHERE menutype = :menutype";
            $checkMenuTypeStmt = $joomla5Conn->prepare($checkMenuTypeQuery);
            $checkMenuTypeStmt->execute([':menutype' => $oldMenuType]);
            $exists = $checkMenuTypeStmt->fetchColumn();

            if (!$exists) {
                // Insert menu type into Joomla 5
                $insertMenuTypeParams = [
                    ':menutype' => $menuType['menutype'],
                    ':title' => $menuType['title'],
                    ':description' => $menuType['description'] ?? '',
                    // Add other fields if they exist in J5 and you want to migrate them
                ];

                $mtInsertFields = [];
                $mtInsertPlaceholders = [];
                $mtExecuteParams = [];
                foreach($insertMenuTypeParams as $key => $value) {
                    $field = ltrim($key, ':');
                    if (in_array($field, $j5MenuTypeFields)) {
                        $mtInsertFields[] = "`" . $field . "`";
                        $mtInsertPlaceholders[] = $key;
                        $mtExecuteParams[$key] = $value;
                    }
                }

                 if (!empty($mtInsertFields)) {
                    $insertMenuTypeQuery = "INSERT INTO {$j5Prefix}_menu_types (" . implode(', ', $mtInsertFields) . ") VALUES (" . implode(', ', $mtInsertPlaceholders) . ")";
                    $insertMenuTypeStmt = $joomla5Conn->prepare($insertMenuTypeQuery);
                    $insertMenuTypeStmt->execute($mtExecuteParams);
                    $menuTypeCount++;
                    echo "Inserted Menu Type: " . htmlspecialchars($oldMenuType) . "\n<br>";
                } else {
                     echo "Warning: Could not build menu type insert query for '{$oldMenuType}'.\n<br>";
                }
            } else {
                echo "Menu Type already exists: " . htmlspecialchars($oldMenuType) . "\n<br>";
            }
        }
        echo "Finished importing/checking $menuTypeCount menu types.\n<br>";
    } catch (PDOException $e) {
        echo "Error importing menu types: " . $e->getMessage() . "\n<br>";
        // Decide whether to continue or stop
    }

    // --- 2. Import Menu Items ---
    echo "<h3>Importing Menu Items...</h3>";
    try {
        // Fetch ordered by lft to process parents before children (helps with parent_id mapping)
        $fetchMenuItemsQuery = "SELECT * FROM {$j4Prefix}_menu ORDER BY lft ASC";
        $fetchMenuItemsStmt = $joomla4Conn->query($fetchMenuItemsQuery);

        // Need to fetch all first to build the parent map correctly in order
        $menuItems = $fetchMenuItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalMenuItems = count($menuItems);
        echo "Found $totalMenuItems menu items in Joomla 4.\n<br>";

        foreach ($menuItems as $menuItem) {
            $oldMenuId = $menuItem['id'];
            $oldParentId = $menuItem['parent_id'];
            echo "Processing Menu Item ID (J4): $oldMenuId - Title: " . htmlspecialchars($menuItem['title']) . "\n<br>";

            // Determine new parent ID
            $newParentId = $menuIdMap[$oldParentId] ?? 1; // Default to root if parent mapping not found

            // Prepare data for Joomla 5 insertion
            $processedLink = $menuItem['link'] ?? '';
            $componentOption = null;
            // --- Start Modification ---
            $newComponentId = 0; // Default component_id to 0 instead of NULL
            // --- End Modification ---

            // Process link for component and potential article ID mapping
            if (strpos($processedLink, 'index.php?') !== false) {
                parse_str(parse_url($processedLink, PHP_URL_QUERY), $queryParams);
                $componentOption = $queryParams['option'] ?? null;

                // Map article ID if it's a com_content link
                if ($componentOption === 'com_content' && isset($queryParams['view']) && $queryParams['view'] === 'article' && isset($queryParams['id'])) {
                    // ... (article ID mapping code remains the same) ...
                }

                // --- Start Modification ---
                // Get component ID from J5 extensions map ONLY if componentOption was found
                if ($componentOption && isset($componentMap[$componentOption])) {
                    $newComponentId = $componentMap[$componentOption]; // Assign the found ID
                } elseif ($componentOption) {
                    // Component option exists in link but not found in J5 map
                    echo "Warning: Component '$componentOption' not found in Joomla 5 extensions map for menu item '$menuItem[title]'. Using component_id 0.\n<br>";
                    // $newComponentId remains 0 (the default)
                }
                // If no componentOption, $newComponentId remains 0 (correct for URL, Alias, Separator etc.)
                // --- End Modification ---

            } // else: Link doesn't contain 'index.php?', likely external URL or similar, component_id 0 is appropriate.


            $insertMenuItemParams = [
                ':asset_id' => 0, // Set to 0 for Joomla DB Fix tool
                ':menutype' => $menuItem['menutype'],
                ':title' => $menuItem['title'],
                ':alias' => $menuItem['alias'] ?: str_replace(' ', '-', strtolower($menuItem['title'])),
                ':note' => $menuItem['note'] ?? '',
                ':path' => $menuItem['path'] ?? ($menuItem['alias'] ?: str_replace(' ', '-', strtolower($menuItem['title']))), // Path might need rebuild
                ':link' => $processedLink,
                ':type' => $menuItem['type'], // e.g., 'component', 'url', 'alias', 'separator'
                ':published' => $menuItem['published'] ?? 0,
                ':parent_id' => $newParentId,
                ':level' => $menuItem['level'] ?? 0, // Will be fixed by Menu Rebuild
                ':component_id' => $newComponentId, // Use mapped component ID (now defaults to 0)
                ':ordering' => $menuItem['ordering'] ?? 0,
                ':browserNav' => $menuItem['browserNav'] ?? 0,
                ':access' => $menuItem['access'] ?? 1,
                ':params' => $menuItem['params'] ?? '{}',
                ':lft' => 0, // Will be fixed by Menu Rebuild
                ':rgt' => 0, // Will be fixed by Menu Rebuild
                ':home' => $menuItem['home'] ?? 0,
                ':language' => $menuItem['language'] ?? '*',
                ':client_id' => $menuItem['client_id'] ?? 0, // 0 for site, 1 for admin
                ':template_style_id' => $menuItem['template_style_id'] ?? 0,
                // --- Start Modification ---
                // Add the 'img' field, defaulting to an empty string if not present in source
                ':img' => $menuItem['img'] ?? '',
                // --- End Modification ---
            ];

            // Insert menu item into Joomla 5
            $miInsertFields = [];
            $miInsertPlaceholders = [];
            $miExecuteParams = [];
             foreach ($insertMenuItemParams as $key => $value) {
                $field = ltrim($key, ':');
                // Ensure the dynamic field check includes 'img' if it exists in J5 table
                if (in_array($field, $j5MenuFields)) {
                    $miInsertFields[] = "`" . $field . "`";
                    $miInsertPlaceholders[] = $key;
                    // Provide empty string specifically for img if value is null
                    if ($field === 'img' && is_null($value)) {
                         $miExecuteParams[$key] = '';
                    } else {
                         $miExecuteParams[$key] = $value;
                    }
                }
            }

            if (!empty($miInsertFields)) {
                $insertMenuItemQuery = "INSERT INTO {$j5Prefix}_menu (" . implode(', ', $miInsertFields) . ") VALUES (" . implode(', ', $miInsertPlaceholders) . ")";
                $insertMenuItemStmt = $joomla5Conn->prepare($insertMenuItemQuery);
                // Line 528 might be here now:
                $insertMenuItemStmt->execute($miExecuteParams);
                $newMenuId = $joomla5Conn->lastInsertId();

                // Store mapping for child items
                $menuIdMap[$oldMenuId] = $newMenuId;
                $menuItemCount++;
                // echo "Inserted Menu Item: '$menuItem[title]'. Old ID: $oldMenuId -> New ID: $newMenuId. New Parent ID: $newParentId\n<br>";
            } else {
                 echo "Error: Could not build menu item insert query for '$menuItem[title]' (Old ID: $oldMenuId) - no matching fields found?\n<br>";
            }
             if ($menuItemCount % 100 == 0) {
                 echo "Processed $menuItemCount menu items...\n<br>";
             }

        } // End foreach menu item loop
        echo "Finished importing $menuItemCount menu items.\n<br>";

    } catch (PDOException $e) {
        echo "Error importing menu items: " . $e->getMessage() . "\n<br>";
        echo "Trace: <pre>" . $e->getTraceAsString() . "</pre>\n<br>";
        // Rollback might be needed if this is part of the main transaction
        throw $e; // Re-throw to trigger rollback if desired
    }

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // +++ END MENU IMPORT SECTION ++++++++++++++++++++++++++++++++++
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++


    // --- Commit Transaction --- (If menu import is inside the main transaction)
    // $joomla5Conn->commit(); // Commit only once at the very end
    // echo "Transaction committed successfully.\n<br>"; // Move commit message to the end

    // Update final instructions
    echo "<hr>";
    echo "<strong>IMPORTANT NEXT STEPS (After Script Finishes):</strong><br>";
    echo "1. Go to your Joomla 5 Admin Panel.<br>";
    echo "2. Navigate to <strong>System -> Maintenance -> Database</strong>.<br>";
    echo "3. Click the <strong>Fix</strong> button (if available) to repair schema issues and generate asset table entries for content AND menus.<br>";
    echo "4. Navigate to <strong>Menus -> Manage</strong>.<br>";
    echo "5. For EACH imported menu (e.g., 'Main Menu'), select it and click the <strong>Rebuild</strong> button in the toolbar. This fixes the menu hierarchy (parent/child relationships, levels).<br>";
    echo "6. Navigate to <strong>System -> Maintenance -> Clear Cache</strong> and delete all cache.<br>";
    echo "7. Check your articles AND menus in the admin and frontend.<br>";

    // --- Commit Transaction ---
    $joomla5Conn->commit();
    echo "Transaction committed successfully.\n<br>";
    echo "<h2>Import Script Complete!</h2>";
    echo "Attempted to import $articleCount out of $totalArticles articles.\n<br>";
    echo "<hr>";
    echo "<strong>IMPORTANT NEXT STEPS:</strong><br>";
    echo "1. Go to your Joomla 5 Admin Panel.<br>";
    echo "2. Navigate to <strong>System -> Maintenance -> Database</strong>.<br>";
    echo "3. Click the <strong>Fix</strong> button (if available) to repair schema issues and generate asset table entries.<br>";
    echo "4. Navigate to <strong>System -> Maintenance -> Clear Cache</strong> and delete all cache.<br>";
    echo "5. Check if your articles are now visible under Content -> Articles.<br>";


} catch (PDOException $e) {
    // --- Rollback Transaction on Error ---
    if ($joomla5Conn->inTransaction()) {
        $joomla5Conn->rollBack();
        echo "Transaction rolled back due to error.\n<br>";
    }
    echo "<h2>Error during import:</h2>";
    echo "Message: " . $e->getMessage() . "\n<br>";
    echo "File: " . $e->getFile() . "\n<br>";
    echo "Line: " . $e->getLine() . "\n<br>";
    echo "Trace: <pre>" . $e->getTraceAsString() . "</pre>\n<br>";

} finally {
    // --- Close Connections ---
    $joomla4Conn = null;
    $joomla5Conn = null;
    echo "Database connections closed.\n<br>";
}




?>
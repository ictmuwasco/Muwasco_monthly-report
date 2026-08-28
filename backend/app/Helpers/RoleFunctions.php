<?php
// role_functions.php


/**
 * Get all roles for dropdown
 */
function getAllRoles($conn) {
    $roles = [];
    $query = "SELECT id, name, description FROM roles ORDER BY name";
    $result = $conn->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
    }
    
    return $roles;
}

/**
 * Get role by ID
 */
function getRoleById($conn, $role_id) {
    $stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $role = $result->fetch_assoc();
    $stmt->close();
    
    return $role;
}

/**
 * Get parameters assigned to a role
 */
function getRoleParameters($conn, $role_id) {
    $parameters = [];
    
    $stmt = $conn->prepare("
        SELECT p.* 
        FROM parameters p
        JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
        WHERE rpa.role_id = ?
        ORDER BY p.code
    ");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $parameters[] = $row;
    }
    
    $stmt->close();
    return $parameters;
}

/**
 * Get categories assigned to a role
 */
function getRoleCategories($conn, $role_id) {
    $categories = [];
    
    $stmt = $conn->prepare("
        SELECT DISTINCT pc.* 
        FROM parameter_categories pc
        JOIN parameters p ON pc.id = p.category_id
        JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
        WHERE rpa.role_id = ?
        ORDER BY pc.display_order
    ");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    
    $stmt->close();
    return $categories;
}

/**
 * Assign parameter to role
 */
function assignParameterToRole($conn, $role_id, $parameter_id) {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO role_parameter_assignments (role_id, parameter_id) 
        VALUES (?, ?)
    ");
    $stmt->bind_param("ii", $role_id, $parameter_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Remove parameter from role
 */
function removeParameterFromRole($conn, $role_id, $parameter_id) {
    $stmt = $conn->prepare("
        DELETE FROM role_parameter_assignments 
        WHERE role_id = ? AND parameter_id = ?
    ");
    $stmt->bind_param("ii", $role_id, $parameter_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Assign category to role
 */
function assignCategoryToRole($conn, $role_id, $category_id) {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO role_category_assignments (role_id, category_id) 
        VALUES (?, ?)
    ");
    $stmt->bind_param("ii", $role_id, $category_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Remove category from role
 */
function removeCategoryFromRole($conn, $role_id, $category_id) {
    $stmt = $conn->prepare("
        DELETE FROM role_category_assignments 
        WHERE role_id = ? AND category_id = ?
    ");
    $stmt->bind_param("ii", $role_id, $category_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Get users by role
 */
function getUsersByRole($conn, $role_id) {
    $users = [];
    
    $stmt = $conn->prepare("
        SELECT id, username, first_name, last_name, surname, email, is_active
        FROM users 
        WHERE role_id = ?
        ORDER BY first_name, last_name
    ");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    $stmt->close();
    return $users;
}

/**
 * Get all users with their role information
 */
function getAllUsersWithRoles($conn) {
    $users = [];
    
    $query = "
        SELECT 
            u.id, 
            u.username, 
            u.first_name, 
            u.last_name, 
            u.surname,
            u.email, 
            u.is_active,
            u.role_id,
            r.name as role_name,
            r.description as role_description
        FROM users u
        JOIN roles r ON u.role_id = r.id
        ORDER BY u.first_name, u.last_name
    ";
    
    $result = $conn->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    
    return $users;
}
?>
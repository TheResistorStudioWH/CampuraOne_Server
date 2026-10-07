CREATE TABLE `advertisements` (
  `adID` int(11) NOT NULL,
  `saleID` int(11) NOT NULL,
  `startTime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `endTime` datetime DEFAULT NULL,
  `type` varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT 'S',
  `img` varchar(500) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `announcements` (
  `announceID` int(11) NOT NULL,
  `schoolID` int(11) NOT NULL,
  `content` longtext NOT NULL,
  `type` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '0-短+日常,1-短+紧急,2-重要',
  `startTime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `endTime` datetime DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `announcement_targets` (
  `targetID` int(11) NOT NULL,
  `announceID` int(11) NOT NULL,
  `targetJSON` json DEFAULT NULL COMMENT '{\r\n  "allSchool": BOOL,\r\n  "areaIDs": [],\r\n  "departmentIDs": [],\r\n  "classIDs": [],\r\n  "studentIDs": []\r\n}'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `app_users` (
  `userID` int(11) NOT NULL,
  `userName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `userImg` varchar(500) DEFAULT NULL,
  `passwordHash` varchar(255) NOT NULL,
  `shopIDs` json DEFAULT NULL,
  `studentID` int(11) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `campuses` (
  `areaID` int(11) NOT NULL,
  `areaName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `areaAddress` json DEFAULT NULL,
  `schoolID` int(11) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `compounds` (
  `compoundID` int(11) NOT NULL,
  `compoundName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `schoolID` int(11) NOT NULL,
  `departmentIDs` json DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `course_tables` (
  `tableID` int(11) NOT NULL,
  `schoolID` int(11) NOT NULL DEFAULT '1',
  `compoundID` int(11) NOT NULL,
  `departmentID` int(11) NOT NULL,
  `classID` int(11) NOT NULL,
  `content` longtext NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `departments` (
  `departmentID` int(11) NOT NULL,
  `departmentName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `compoundID` int(11) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `main_shops` (
  `shopID` int(11) NOT NULL,
  `shopName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `shopAddress` json DEFAULT NULL,
  `shopSlogan` longtext,
  `subShopsList` json DEFAULT NULL,
  `productsList` json DEFAULT NULL,
  `adIDs` json DEFAULT NULL,
  `shopLogo` varchar(500) DEFAULT NULL,
  `shopImg` varchar(500) DEFAULT NULL,
  `saleIDs` json DEFAULT NULL,
  `schoolID` int(11) NOT NULL,
  `areaID` int(11) DEFAULT NULL,
  `accountID` int(11) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `products` (
  `productID` int(11) NOT NULL,
  `productName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `categoryID` int(11) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `productImg` varchar(500) DEFAULT NULL,
  `productIntro` longtext,
  `mainShopID` int(11) NOT NULL,
  `subShopID` int(11) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `product_categories` (
  `categoryID` int(11) NOT NULL,
  `categoryName` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoryIcon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoryColor` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `promotion_events` (
  `saleID` int(11) NOT NULL,
  `productID` int(11) DEFAULT NULL,
  `saleRule` longtext,
  `startTime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `endTime` datetime DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `schools` (
  `schoolID` int(11) NOT NULL,
  `schoolName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `schoolAddress` json DEFAULT NULL,
  `schoolLogo` varchar(500) DEFAULT NULL,
  `areaIDs` json DEFAULT NULL,
  `passwordHash` varchar(255) NOT NULL,
  `compoundIDs` json DEFAULT NULL,
  `schoolCalendar` longtext,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `shop_applications` (
  `applicationID` int(11) NOT NULL,
  `accountID` int(11) NOT NULL,
  `schoolID` int(11) NOT NULL,
  `shopName` varchar(255) NOT NULL,
  `shopLogo` varchar(255) DEFAULT NULL,
  `shopSlogan` text,
  `shopAddress` json DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '0',
  `reviewComment` text,
  `createdAt` datetime DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `students` (
  `studentID` int(11) NOT NULL,
  `schoolID` int(11) NOT NULL DEFAULT '1',
  `studentName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `gender` varchar(20) CHARACTER SET utf8 DEFAULT NULL,
  `compoundID` int(11) NOT NULL,
  `departmentID` int(11) NOT NULL,
  `classID` int(11) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `sub_shops` (
  `shopID` int(11) NOT NULL,
  `shopName` varchar(100) CHARACTER SET utf8 NOT NULL,
  `shopAddress` json DEFAULT NULL,
  `productsList` json DEFAULT NULL,
  `shopImg` varchar(500) DEFAULT NULL,
  `mainShopID` int(11) NOT NULL,
  `saleList` json DEFAULT NULL,
  `takeOutLink` varchar(500) DEFAULT NULL,
  `accountID` int(11) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `advertisements`
  ADD PRIMARY KEY (`adID`),
  ADD KEY `fk_advertisements_sale` (`saleID`);
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announceID`),
  ADD KEY `fk_announcements_school` (`schoolID`);
ALTER TABLE `announcement_targets`
  ADD PRIMARY KEY (`targetID`),
  ADD KEY `idx_announceID` (`announceID`);
ALTER TABLE `app_users`
  ADD PRIMARY KEY (`userID`),
  ADD KEY `fk_app_users_student` (`studentID`);
ALTER TABLE `campuses`
  ADD PRIMARY KEY (`areaID`),
  ADD KEY `fk_campuses_school` (`schoolID`);
ALTER TABLE `compounds`
  ADD PRIMARY KEY (`compoundID`),
  ADD KEY `fk_compounds_school` (`schoolID`);
ALTER TABLE `course_tables`
  ADD PRIMARY KEY (`tableID`),
  ADD KEY `fk_course_tables_compound` (`compoundID`),
  ADD KEY `fk_course_tables_department` (`departmentID`),
  ADD KEY `idx_course_tables_scope` (`schoolID`,`compoundID`,`departmentID`,`classID`);
ALTER TABLE `departments`
  ADD PRIMARY KEY (`departmentID`),
  ADD KEY `fk_departments_compound` (`compoundID`);
ALTER TABLE `main_shops`
  ADD PRIMARY KEY (`shopID`),
  ADD KEY `fk_main_shops_school` (`schoolID`),
  ADD KEY `fk_main_shops_area` (`areaID`),
  ADD KEY `fk_main_shops_account` (`accountID`);
ALTER TABLE `products`
  ADD PRIMARY KEY (`productID`),
  ADD KEY `fk_products_sub_shop` (`subShopID`),
  ADD KEY `fk_products_main_shop` (`mainShopID`),
  ADD KEY `idx_products_categoryID` (`categoryID`);
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`categoryID`),
  ADD UNIQUE KEY `uk_product_categories_name` (`categoryName`);
ALTER TABLE `promotion_events`
  ADD PRIMARY KEY (`saleID`),
  ADD KEY `fk_promotion_events_product` (`productID`);
ALTER TABLE `schools`
  ADD PRIMARY KEY (`schoolID`);
ALTER TABLE `shop_applications`
  ADD PRIMARY KEY (`applicationID`),
  ADD KEY `idx_school` (`schoolID`),
  ADD KEY `idx_account` (`accountID`),
  ADD KEY `idx_status` (`status`);
ALTER TABLE `students`
  ADD PRIMARY KEY (`studentID`),
  ADD KEY `fk_students_compound` (`compoundID`),
  ADD KEY `fk_students_department` (`departmentID`),
  ADD KEY `idx_schoolID` (`schoolID`);
ALTER TABLE `sub_shops`
  ADD PRIMARY KEY (`shopID`),
  ADD KEY `fk_sub_shops_main` (`mainShopID`),
  ADD KEY `fk_sub_shops_account` (`accountID`);
ALTER TABLE `advertisements`
  MODIFY `adID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
ALTER TABLE `announcements`
  MODIFY `announceID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;
ALTER TABLE `announcement_targets`
  MODIFY `targetID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;
ALTER TABLE `app_users`
  MODIFY `userID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
ALTER TABLE `campuses`
  MODIFY `areaID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `compounds`
  MODIFY `compoundID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `course_tables`
  MODIFY `tableID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;
ALTER TABLE `departments`
  MODIFY `departmentID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `main_shops`
  MODIFY `shopID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `products`
  MODIFY `productID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
ALTER TABLE `product_categories`
  MODIFY `categoryID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;
ALTER TABLE `promotion_events`
  MODIFY `saleID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
ALTER TABLE `schools`
  MODIFY `schoolID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `shop_applications`
  MODIFY `applicationID` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `sub_shops`
  MODIFY `shopID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `advertisements`
  ADD CONSTRAINT `fk_advertisements_sale` FOREIGN KEY (`saleID`) REFERENCES `promotion_events` (`saleID`);
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announcements_school` FOREIGN KEY (`schoolID`) REFERENCES `schools` (`schoolID`);
ALTER TABLE `announcement_targets`
  ADD CONSTRAINT `fk_announcement_targets_announce` FOREIGN KEY (`announceID`) REFERENCES `announcements` (`announceID`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `app_users`
  ADD CONSTRAINT `fk_app_users_student` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`);
ALTER TABLE `campuses`
  ADD CONSTRAINT `fk_campuses_school` FOREIGN KEY (`schoolID`) REFERENCES `schools` (`schoolID`);
ALTER TABLE `compounds`
  ADD CONSTRAINT `fk_compounds_school` FOREIGN KEY (`schoolID`) REFERENCES `schools` (`schoolID`);
ALTER TABLE `course_tables`
  ADD CONSTRAINT `fk_course_tables_compound` FOREIGN KEY (`compoundID`) REFERENCES `compounds` (`compoundID`),
  ADD CONSTRAINT `fk_course_tables_department` FOREIGN KEY (`departmentID`) REFERENCES `departments` (`departmentID`);
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_departments_compound` FOREIGN KEY (`compoundID`) REFERENCES `compounds` (`compoundID`);
ALTER TABLE `main_shops`
  ADD CONSTRAINT `fk_main_shops_account` FOREIGN KEY (`accountID`) REFERENCES `app_users` (`userID`),
  ADD CONSTRAINT `fk_main_shops_area` FOREIGN KEY (`areaID`) REFERENCES `campuses` (`areaID`),
  ADD CONSTRAINT `fk_main_shops_school` FOREIGN KEY (`schoolID`) REFERENCES `schools` (`schoolID`);
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`categoryID`) REFERENCES `product_categories` (`categoryID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_main_shop` FOREIGN KEY (`mainShopID`) REFERENCES `main_shops` (`shopID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_sub_shop` FOREIGN KEY (`subShopID`) REFERENCES `sub_shops` (`shopID`) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE `promotion_events`
  ADD CONSTRAINT `fk_promotion_events_product` FOREIGN KEY (`productID`) REFERENCES `products` (`productID`);
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_compound` FOREIGN KEY (`compoundID`) REFERENCES `compounds` (`compoundID`),
  ADD CONSTRAINT `fk_students_department` FOREIGN KEY (`departmentID`) REFERENCES `departments` (`departmentID`);
ALTER TABLE `sub_shops`
  ADD CONSTRAINT `fk_sub_shops_account` FOREIGN KEY (`accountID`) REFERENCES `app_users` (`userID`),
  ADD CONSTRAINT `fk_sub_shops_main` FOREIGN KEY (`mainShopID`) REFERENCES `main_shops` (`shopID`);

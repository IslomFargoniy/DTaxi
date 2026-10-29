plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
}

// Mijoz ilovasi (qaror #71): haydovchi ilovasidan alohida (o'z paketi, Google Play), firma sozlamalari umumiy
val firm = Firm(project)
firm.requireReleaseSigning()
val securityResDir = firm.registerNetworkSecurity()
// O'z versiyasi: faqat mijoz ilovasi va umumiy qismni o'zgartirgan commit'lar (haydovchi ilovasidan mustaqil)
val autoBuildNumber: Int = Firm.buildNumber(project, "client", "core")

android {
    namespace = "uz.dtaxi.client"
    compileSdk = 36

    defaultConfig {
        // firm.properties: clientApplicationId (standart — haydovchi paketidan: uz.dtaxi.driver.demo → uz.dtaxi.client.demo)
        applicationId = firm.prop("clientApplicationId").ifBlank { firm.prop("applicationId").replace(".driver", ".client") }
        minSdk = 29
        targetSdk = 36
        versionCode = (project.findProperty("versionCode") as String?)?.toInt() ?: autoBuildNumber
        versionName = (project.findProperty("versionName") as String?) ?: "1.0.$autoBuildNumber"

        resValue("string", "app_name", firm.prop("clientAppName").ifBlank { firm.prop("appName", "DTaxi") })
        buildConfigField("String", "SERVER_URL", "\"${firm.prop("serverUrl").trimEnd('/')}\"")
        buildConfigField("String", "PRIMARY_COLOR", "\"${firm.prop("primaryColor", "#FFC800")}\"")
        buildConfigField("String", "PHONE_COUNTRY", "\"${firm.prop("phoneCountry", "UZ")}\"")
        // Firebase — firmaning o'sha loyihasi, mijoz ilovasi alohida Android ilova sifatida qo'shiladi; bo'sh — push o'chiq
        buildConfigField("String", "FIREBASE_PROJECT_ID", "\"${firm.prop("firebaseProjectId")}\"")
        buildConfigField("String", "FIREBASE_APPLICATION_ID", "\"${firm.prop("clientFirebaseApplicationId")}\"")
        buildConfigField("String", "FIREBASE_API_KEY", "\"${firm.prop("firebaseApiKey")}\"")
        buildConfigField("String", "FIREBASE_SENDER_ID", "\"${firm.prop("firebaseSenderId")}\"")
    }

    signingConfigs {
        if (firm.signingReady) {
            create("firm") {
                storeFile = File(firm.dir, "keystore.jks")
                storePassword = firm.keystore.getProperty("storePassword")
                keyAlias = firm.keystore.getProperty("keyAlias")
                keyPassword = firm.keystore.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.findByName("firm") ?: signingConfigs.getByName("debug").takeIf { firm.debugSigningAllowed }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    sourceSets["main"].res.srcDir(securityResDir)
    buildFeatures {
        compose = true
        buildConfig = true
    }
    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    implementation(project(":core"))
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.ui.graphics)
    implementation(libs.androidx.ui.tooling.preview)
    implementation(libs.androidx.material.icons.extended)
    implementation(libs.retrofit)
    implementation(libs.retrofit.converter.gson)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.coroutines.play.services)
    implementation(libs.maplibre)
    implementation(libs.play.services.location)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
    debugImplementation(libs.androidx.ui.tooling)
    testImplementation(libs.junit)
}

// Sinov uchun APK public/android/client/ ga (Google Play'ga .aab — bundleRelease); haydovchi ilovasi papkasiga tegmaydi
val publicApkDir = rootProject.file("../public/android/client")
listOf("debug", "release").forEach { type ->
    val name = type.replaceFirstChar { it.uppercase() }
    val sync = tasks.register<Sync>("copy${name}ApkToPublic") {
        val version = android.defaultConfig.versionName
        from(layout.buildDirectory.dir("outputs/apk/$type")) { include("*.apk") }
        into(publicApkDir)
        rename { "dtaxi-client.$version" + (if (type == "debug") "-debug" else "") + ".apk" }
    }
    tasks.matching { it.name == "assemble$name" }.configureEach { finalizedBy(sync) }
}
